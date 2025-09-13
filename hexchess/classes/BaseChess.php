<?php
// classes/BaseChess.php - Comprehensive shared foundation for chess variants

abstract class BaseChess {
    // === CORE GAME PROPERTIES ===
    protected $gameId;
    protected $playerCount;
    protected $currentPlayer;
    protected $gameState;
    protected $features;
    protected $capturedPieces;
    
    // === PLAYER MANAGEMENT ===
    protected $players;
    protected $activePlayers;
    protected $playerUsers;
    protected $moveCount;
    
    // === BOARD PROPERTIES (Abstract - implemented by variants) ===
    abstract protected function getBoardSize();
    abstract protected function initializeBoard();
    
    public function __construct($gameId = null, $playerCount = 2, $boardSize = null) {
        $this->gameId = $gameId;
        $this->playerCount = $playerCount;
        $this->currentPlayer = 0;
        $this->features = [];
        $this->capturedPieces = [];
        $this->moveCount = 0;
        
        // Initialize player arrays
        $this->players = $this->getPlayerColors($playerCount);
        $this->activePlayers = array_fill(0, $playerCount, true);
        $this->playerUsers = array_fill(0, $playerCount, null);
        
        $this->gameState = [
            'status' => 'active',
            'moveCount' => 0,
            'gameOver' => false,
            'winner' => null,
            'reason' => null,
            'isInCheck' => false,
            'kingsInCheck' => []
        ];
        
        // Initialize variant-specific board
        $this->initializeBoard();
    }
    
    // === ABSTRACT METHODS FOR VARIANTS ===
    abstract public function getBoard();
    abstract public function getPiece($q, $r);
    abstract public function isValidMove($fromQ, $fromR, $toQ, $toR);
    abstract protected function canPieceMoveTo($piece, $fromQ, $fromR, $toQ, $toR);
    abstract protected function isKingInCheck($player);
    abstract protected function findKing($player);
    
    // === SHARED MOVEMENT SYSTEM ===
    public function movePiece($fromQ, $fromR, $toQ, $toR) {
        if (!$this->isValidMove($fromQ, $fromR, $toQ, $toR)) {
            return false;
        }
        
        $piece = $this->getPiece($fromQ, $fromR);
        $targetPiece = $this->getPiece($toQ, $toR);
        
        // Handle capture
        if ($targetPiece) {
            $this->capturePiece($targetPiece, $this->currentPlayer);
        }
        
        // Execute the move (variant-specific implementation)
        $this->executePieceMove($fromQ, $fromR, $toQ, $toR);
        
        // Advance turn and update game state
        $this->gameState['moveCount']++;
        $this->moveCount++;
        $this->advanceToNextActivePlayer();
        $this->updateGameState();
        
        return true;
    }
    
    // Implement this in variants to execute the actual piece movement
    abstract protected function executePieceMove($fromQ, $fromR, $toQ, $toR);
    
    public function getValidMoves($fromQ, $fromR) {
        $piece = $this->getPiece($fromQ, $fromR);
        if (!$piece || $piece->player !== $this->currentPlayer) {
            return [];
        }
        
        $validMoves = [];
        $possibleMoves = $this->generatePossibleMoves($fromQ, $fromR);
        
        foreach ($possibleMoves as $move) {
            if ($this->isValidMove($fromQ, $fromR, $move['q'], $move['r'])) {
                $targetPiece = $this->getPiece($move['q'], $move['r']);
                $validMoves[] = [
                    'q' => $move['q'],
                    'r' => $move['r'],
                    'type' => $targetPiece ? 'capture' : 'move'
                ];
            }
        }
        
        return $validMoves;
    }
    
    // Generate all possible moves for a piece (variant-specific)
    abstract protected function generatePossibleMoves($fromQ, $fromR);
    
    // === SHARED PLAYER MANAGEMENT ===
    protected function getPlayerColors($playerCount) {
        $colors = ['red', 'blue', 'green', 'yellow', 'purple', 'orange'];
        return array_slice($colors, 0, $playerCount);
    }
    
    public function getCurrentPlayerSlot() {
        return $this->currentPlayer;
    }
    
    public function getCurrentPlayer() {
        return $this->players[$this->currentPlayer];
    }
    
    public function getPlayerCount() {
        return $this->playerCount;
    }
    
    public function getPlayers() {
        return $this->players;
    }
    
    public function getActivePlayers() {
        return $this->activePlayers;
    }
    
    public function isPlayerActive($playerSlot) {
        return isset($this->activePlayers[$playerSlot]) && $this->activePlayers[$playerSlot];
    }
    
    public function setPlayerUser($playerSlot, $userId) {
        if ($playerSlot >= 0 && $playerSlot < $this->playerCount) {
            $this->playerUsers[$playerSlot] = $userId;
        }
    }
    
    public function getPlayerUser($playerSlot) {
        return isset($this->playerUsers[$playerSlot]) ? $this->playerUsers[$playerSlot] : null;
    }
    
    protected function advanceToNextActivePlayer() {
        $startingPlayer = $this->currentPlayer;
        
        do {
            $this->currentPlayer = ($this->currentPlayer + 1) % $this->playerCount;
        } while (!$this->activePlayers[$this->currentPlayer] && $this->currentPlayer !== $startingPlayer);
        
        // If we've cycled back to the starting player and they're inactive, 
        // all players might be eliminated
        if ($this->currentPlayer === $startingPlayer && !$this->activePlayers[$this->currentPlayer]) {
            $this->endGame(null, 'all_eliminated');
        }
    }
    
    protected function getActivePlayerCount() {
        return array_sum($this->activePlayers);
    }
    
    // === SHARED GAME STATE MANAGEMENT ===
    public function isGameOver() {
        return $this->gameState['gameOver'];
    }
    
    protected function updateGameState() {
        $this->checkForCheck();
        $this->checkForGameEnd();
    }
    
    protected function checkForCheck() {
        $this->gameState['kingsInCheck'] = [];
        
        for ($player = 0; $player < $this->playerCount; $player++) {
            if (!$this->activePlayers[$player]) continue;
            
            if ($this->isKingInCheck($player)) {
                $this->gameState['kingsInCheck'][] = $player;
            }
        }
        
        $this->gameState['isInCheck'] = !empty($this->gameState['kingsInCheck']);
    }
    
    protected function checkForGameEnd() {
        $activeCount = $this->getActivePlayerCount();
        
        if ($activeCount <= 1) {
            if ($activeCount === 1) {
                // Find the winner
                for ($player = 0; $player < $this->playerCount; $player++) {
                    if ($this->activePlayers[$player]) {
                        $this->endGame($this->playerUsers[$player], 'elimination');
                        break;
                    }
                }
            } else {
                $this->endGame(null, 'all_eliminated');
            }
        }
        
        // Check for checkmate/stalemate for current player
        if ($this->activePlayers[$this->currentPlayer]) {
            if ($this->isKingInCheck($this->currentPlayer) && !$this->hasLegalMoves($this->currentPlayer)) {
                $this->endGame(null, 'checkmate');
            } elseif (!$this->isKingInCheck($this->currentPlayer) && !$this->hasLegalMoves($this->currentPlayer)) {
                $this->endGame(null, 'stalemate');
            }
        }
    }
    
    protected function hasLegalMoves($player) {
        $pieces = $this->getAllPiecesForPlayer($player);
        
        foreach ($pieces as $piecePos) {
            $validMoves = $this->getValidMoves($piecePos['q'], $piecePos['r']);
            if (!empty($validMoves)) {
                return true;
            }
        }
        
        return false;
    }
    
    // Get all pieces for a player (variant-specific implementation)
    abstract protected function getAllPiecesForPlayer($player);
    
    protected function endGame($winnerId = null, $reason = 'checkmate') {
        $this->gameState['gameOver'] = true;
        $this->gameState['winner'] = $winnerId;
        $this->gameState['reason'] = $reason;
    }
    
    public function resignPlayer($playerSlot) {
        if ($this->isGameOver()) {
            return "Game is already over";
        }
        
        $this->activePlayers[$playerSlot] = false;
        
        if ($this->playerCount == 2) {
            $otherPlayer = ($playerSlot == 0) ? 1 : 0;
            $this->endGame($this->playerUsers[$otherPlayer], 'resignation');
        } else {
            // Multi-player: continue with remaining players
            $this->updateGameState();
        }
        
        return true;
    }
    
    // === SHARED CAPTURE SYSTEM ===
    protected function capturePiece($piece, $capturedBy) {
        if ($this->isShogiDropsEnabled()) {
            // Convert captured piece to capturer's color for dropping
            $this->capturedPieces[] = [
                'type' => $piece->type === 'pawn' ? 'pawn' : $piece->type,
                'captured_by' => $capturedBy,
                'captured_at' => time()
            ];
        }
    }
    
    public function canDropPiece($playerSlot, $pieceType, $q, $r) {
        if (!$this->isShogiDropsEnabled()) {
            return false;
        }
        
        // Check if player has this piece type in captured pieces
        $hasType = false;
        foreach ($this->capturedPieces as $capturedPiece) {
            if ($capturedPiece['captured_by'] === $playerSlot && 
                $capturedPiece['type'] === $pieceType) {
                $hasType = true;
                break;
            }
        }
        
        if (!$hasType) return false;
        
        // Position must be empty
        if ($this->getPiece($q, $r) !== null) return false;
        
        // Additional variant-specific drop rules
        return $this->isValidDropPosition($playerSlot, $pieceType, $q, $r);
    }
    
    abstract protected function isValidDropPosition($playerSlot, $pieceType, $q, $r);
    
    public function dropPiece($playerSlot, $pieceType, $q, $r) {
        if (!$this->canDropPiece($playerSlot, $pieceType, $q, $r)) {
            return false;
        }
        
        // Remove piece from captured pieces
        for ($i = 0; $i < count($this->capturedPieces); $i++) {
            $capturedPiece = $this->capturedPieces[$i];
            if ($capturedPiece['captured_by'] === $playerSlot && 
                $capturedPiece['type'] === $pieceType) {
                array_splice($this->capturedPieces, $i, 1);
                break;
            }
        }
        
        // Place the piece (variant-specific)
        $this->executePieceDrop($playerSlot, $pieceType, $q, $r);
        
        // Advance turn
        $this->gameState['moveCount']++;
        $this->moveCount++;
        $this->advanceToNextActivePlayer();
        $this->updateGameState();
        
        return true;
    }
    
    abstract protected function executePieceDrop($playerSlot, $pieceType, $q, $r);
    
    // === SHARED FEATURE SYSTEM ===
    public function enableFogOfWar() {
        $this->features['fog_of_war'] = true;
        return $this;
    }
    
    public function enableShogiDrops() {
        $this->features['shogi_drops'] = true;
        return $this;
    }
    
    public function isFogOfWarEnabled() {
        return isset($this->features['fog_of_war']) && $this->features['fog_of_war'];
    }
    
    public function isShogiDropsEnabled() {
        return isset($this->features['shogi_drops']) && $this->features['shogi_drops'];
    }
    
    // === SHARED GAME STATE API ===
    public function getGameState() {
        return [
            'currentPlayer' => $this->getCurrentPlayerSlot(),
            'playerCount' => $this->getPlayerCount(),
            'gameStatus' => [
                'gameOver' => $this->isGameOver(),
                'winner' => $this->gameState['winner'],
                'reason' => $this->gameState['reason']
            ],
            'moveCount' => $this->gameState['moveCount'],
            'isInCheck' => $this->gameState['isInCheck'],
            'kingsInCheck' => $this->gameState['kingsInCheck'],
            'features' => $this->features,
            'activePlayers' => $this->activePlayers,
            'players' => $this->players,
            'playerUsers' => $this->playerUsers
        ];
    }
    
    public function canUserMove($userId) {
        if ($this->isGameOver()) {
            return false;
        }
        
        $currentPlayerUser = $this->getPlayerUser($this->currentPlayer);
        return $currentPlayerUser === $userId;
    }
    
    // === SHARED SERIALIZATION ===
    public function getSerializableData() {
        return [
            'gameId' => $this->gameId,
            'playerCount' => $this->playerCount,
            'currentPlayer' => $this->currentPlayer,
            'gameState' => $this->gameState,
            'features' => $this->features,
            'capturedPieces' => $this->capturedPieces,
            'players' => $this->players,
            'activePlayers' => $this->activePlayers,
            'playerUsers' => $this->playerUsers,
            'moveCount' => $this->moveCount,
            'variantData' => $this->getVariantSpecificData()
        ];
    }
    
    public function restoreFromData($data) {
        $this->gameId = $data['gameId'] ?? null;
        $this->playerCount = $data['playerCount'] ?? 2;
        $this->currentPlayer = $data['currentPlayer'] ?? 0;
        $this->gameState = $data['gameState'] ?? [];
        $this->features = $data['features'] ?? [];
        $this->capturedPieces = $data['capturedPieces'] ?? [];
        $this->players = $data['players'] ?? $this->getPlayerColors($this->playerCount);
        $this->activePlayers = $data['activePlayers'] ?? array_fill(0, $this->playerCount, true);
        $this->playerUsers = $data['playerUsers'] ?? array_fill(0, $this->playerCount, null);
        $this->moveCount = $data['moveCount'] ?? 0;
        
        if (isset($data['variantData'])) {
            $this->restoreVariantSpecificData($data['variantData']);
        }
    }
    
    abstract protected function getVariantSpecificData();
    abstract protected function restoreVariantSpecificData($data);
    
    // === UTILITY METHODS ===
    public function getVariantType() {
        $className = get_class($this);
        $variants = [
            'HexChess' => 'hex',
            'CommandChess' => 'command',
            'StandardChess' => 'standard',
            'SpaceChess' => 'space',
            'TimeChess' => 'time',
            'GalaxyChess' => 'galaxy'
        ];
        return $variants[$className] ?? 'unknown';
    }
    
    public function getGameId() {
        return $this->gameId;
    }
    
    // === DEBUGGING SUPPORT ===
    public function debugGameState() {
        return [
            'variant' => $this->getVariantType(),
            'currentPlayer' => $this->currentPlayer,
            'activePlayers' => $this->activePlayers,
            'playerUsers' => $this->playerUsers,
            'moveCount' => $this->moveCount,
            'gameOver' => $this->isGameOver(),
            'inCheck' => $this->gameState['isInCheck'],
            'kingsInCheck' => $this->gameState['kingsInCheck']
        ];
    }
}

// === COORDINATE HELPER TRAITS ===

trait HexagonalCoordinates {
    protected function isValidHex($q, $r) {
        $boardSize = $this->getBoardSize();
        return abs($q) <= $boardSize && 
               abs($r) <= $boardSize && 
               abs($q + $r) <= $boardSize;
    }
    
    protected function getHexNeighbors($q, $r) {
        return [
            ['q' => $q + 1, 'r' => $r],     // East
            ['q' => $q - 1, 'r' => $r],     // West  
            ['q' => $q, 'r' => $r + 1],     // Southeast
            ['q' => $q, 'r' => $r - 1],     // Northwest
            ['q' => $q + 1, 'r' => $r - 1], // Northeast
            ['q' => $q - 1, 'r' => $r + 1]  // Southwest
        ];
    }
    
    protected function hexDistance($q1, $r1, $q2, $r2) {
        return (abs($q1 - $q2) + abs($q1 + $r1 - $q2 - $r2) + abs($r1 - $r2)) / 2;
    }
}

trait StandardCoordinates {
    protected function isValidSquare($file, $rank) {
        return $file >= 'a' && $file <= 'h' && $rank >= 1 && $rank <= 8;
    }
    
    protected function fileToIndex($file) {
        return ord($file) - ord('a');
    }
    
    protected function indexToFile($index) {
        return chr(ord('a') + $index);
    }
}
?>