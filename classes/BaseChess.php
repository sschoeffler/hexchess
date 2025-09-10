<?php
// classes/BaseChess.php - Practical base class for chess variants

abstract class BaseChess {
    protected $gameId;
    protected $playerCount;
    protected $currentPlayer;
    protected $gameState;
    protected $features;
    
    // === CORE ABSTRACT METHODS ===
    
    abstract public function getBoard();
    abstract public function movePiece($fromQ, $fromR, $toQ, $toR);
    abstract public function getValidMoves($fromQ, $fromR);
    abstract public function isGameOver();
    abstract public function getCurrentPlayerSlot();
    abstract public function getPiece($q, $r);
    
    // === SHARED CONSTRUCTOR ===
    
    public function __construct($gameId = null, $playerCount = 2, $boardSize = null) {
        $this->gameId = $gameId;
        $this->playerCount = $playerCount;
        $this->currentPlayer = 0;
        $this->features = [];
        $this->gameState = [
            'status' => 'active',
            'moveCount' => 0,
            'gameOver' => false,
            'winner' => null,
            'reason' => null,
            'isInCheck' => false,
            'kingsInCheck' => []
        ];
        $this->initializeBoard($boardSize);
    }
    
    abstract protected function initializeBoard($boardSize);
    
    // === FEATURE SYSTEM ===
    
    public function enableFogOfWar() {
        $this->features['fog_of_war'] = true;
    }
    
    public function enableShogiDrops() {
        $this->features['shogi_drops'] = true;
    }
    
    public function isFogOfWarEnabled() {
        return isset($this->features['fog_of_war']) && $this->features['fog_of_war'];
    }
    
    public function isShogiDropsEnabled() {
        return isset($this->features['shogi_drops']) && $this->features['shogi_drops'];
    }
    
    public function hasFeature($feature) {
        return isset($this->features[$feature]) && $this->features[$feature];
    }
    
    // === FOG OF WAR SUPPORT ===
    
    /**
     * Get visible pieces for a specific player (fog of war)
     * @param int $playerSlot Player's perspective
     * @return array Visible piece positions
     */
    public function getVisiblePieces($playerSlot) {
        if (!$this->isFogOfWarEnabled()) {
            return $this->getAllPiecePositions();
        }
        
        $visible = [];
        $playerPieces = $this->getPlayerPieces($playerSlot);
        
        foreach ($playerPieces as $piece) {
            // Player can always see their own pieces
            $visible[] = ['q' => $piece['q'], 'r' => $piece['r']];
            
            // Can see pieces they can attack
            $validMoves = $this->getValidMoves($piece['q'], $piece['r']);
            foreach ($validMoves as $move) {
                if ($move['type'] === 'capture') {
                    $visible[] = ['q' => $move['q'], 'r' => $move['r']];
                }
            }
            
            // Can see adjacent enemy pieces
            $neighbors = $this->getAdjacentPositions($piece['q'], $piece['r']);
            foreach ($neighbors as $pos) {
                $neighborPiece = $this->getPiece($pos['q'], $pos['r']);
                if ($neighborPiece && $neighborPiece->player !== $playerSlot) {
                    $visible[] = ['q' => $pos['q'], 'r' => $pos['r']];
                }
            }
        }
        
        return array_unique($visible, SORT_REGULAR);
    }
    
    abstract protected function getAllPiecePositions();
    abstract protected function getPlayerPieces($playerSlot);
    abstract protected function getAdjacentPositions($q, $r);
    
    // === SHOGI DROPS SUPPORT ===
    
    protected $capturedPieces = [];
    
    public function capturePiece($piece) {
        if ($this->isShogiDropsEnabled()) {
            $this->capturedPieces[] = [
                'type' => $piece->type,
                'original_player' => $piece->player,
                'captured_by' => $this->getCurrentPlayerSlot(),
                'captured_at' => $this->gameState['moveCount']
            ];
        }
    }
    
    public function getCapturedPieces($playerSlot) {
        if (!$this->isShogiDropsEnabled()) {
            return [];
        }
        
        return array_filter($this->capturedPieces, function($piece) use ($playerSlot) {
            return $piece['captured_by'] === $playerSlot;
        });
    }
    
    public function canDropPiece($playerSlot, $pieceType, $q, $r) {
        if (!$this->isShogiDropsEnabled()) {
            return false;
        }
        
        // Check if player has this piece type in reserves
        $available = $this->getCapturedPieces($playerSlot);
        $hasType = false;
        foreach ($available as $piece) {
            if ($piece['type'] === $pieceType) {
                $hasType = true;
                break;
            }
        }
        
        if (!$hasType) return false;
        
        // Check if position is valid and empty
        if ($this->getPiece($q, $r) !== null) return false;
        
        // Additional variant-specific drop rules
        return $this->isValidDropPosition($playerSlot, $pieceType, $q, $r);
    }
    
    abstract protected function isValidDropPosition($playerSlot, $pieceType, $q, $r);
    
    // === SHARED GAME STATE ===
    
    public function getPlayerCount() {
        return $this->playerCount;
    }
    
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
            'features' => $this->features
        ];
    }
    
    public function canUserMove($userId) {
        // Basic implementation - will be enhanced when integrating with user system
        return !$this->isGameOver();
    }
    
    public function resignPlayer($playerSlot) {
        if ($this->isGameOver()) {
            return "Game is already over";
        }
        
        if ($this->playerCount == 2) {
            $otherPlayer = ($playerSlot == 0) ? 1 : 0;
            $this->endGame($otherPlayer, 'resignation');
            return true;
        }
        
        // Multi-player resignation - implement in subclasses
        return $this->handleMultiPlayerResignation($playerSlot);
    }
    
    protected function handleMultiPlayerResignation($playerSlot) {
        $this->endGame(null, 'resignation');
        return true;
    }
    
    protected function endGame($winnerId = null, $reason = 'checkmate') {
        $this->gameState['gameOver'] = true;
        $this->gameState['winner'] = $winnerId;
        $this->gameState['reason'] = $reason;
    }
    
    // === VARIANT DETECTION ===
    
    public function getVariantType() {
        $className = get_class($this);
        $variants = [
            'HexChess' => 'hex',
            'CommandChess' => 'command',
            'StandardChess' => 'standard',
            'GlinskiChess' => 'glinski',
            'SpaceChess' => 'space',
            'TimeChess' => 'time',
            'GalaxyChess' => 'galaxy'
        ];
        return $variants[$className] ?? 'unknown';
    }
    
    // === SERIALIZATION FOR DATABASE STORAGE ===
    
    public function getSerializableData() {
        return [
            'gameId' => $this->gameId,
            'playerCount' => $this->playerCount,
            'currentPlayer' => $this->currentPlayer,
            'gameState' => $this->gameState,
            'features' => $this->features,
            'capturedPieces' => $this->capturedPieces,
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
        
        if (isset($data['variantData'])) {
            $this->restoreVariantSpecificData($data['variantData']);
        }
    }
    
    // Implement these in subclasses for variant-specific data
    abstract protected function getVariantSpecificData();
    abstract protected function restoreVariantSpecificData($data);
}

// === COORDINATE HELPER TRAITS ===

trait HexagonalMovement {
    protected function isValidHex($q, $r) {
        return abs($q) <= $this->getBoardSize() && 
               abs($r) <= $this->getBoardSize() && 
               abs($q + $r) <= $this->getBoardSize();
    }
    
    protected function getHexNeighbors($q, $r) {
        return [
            ['q' => $q + 1, 'r' => $r],
            ['q' => $q - 1, 'r' => $r],
            ['q' => $q, 'r' => $r + 1],
            ['q' => $q, 'r' => $r - 1],
            ['q' => $q + 1, 'r' => $r - 1],
            ['q' => $q - 1, 'r' => $r + 1]
        ];
    }
    
    protected function hexDistance($q1, $r1, $q2, $r2) {
        return (abs($q1 - $q2) + abs($q1 + $r1 - $q2 - $r2) + abs($r1 - $r2)) / 2;
    }
}

trait StandardMovement {
    protected function isValidSquare($file, $rank) {
        return $file >= 'a' && $file <= 'h' && $rank >= 1 && $rank <= 8;
    }
    
    protected function fileToIndex($file) {
        return ord($file) - ord('a');
    }
    
    protected function indexToFile($index) {
        return chr(ord('a') + $index);
    }
    
    protected function getSquareNeighbors($file, $rank) {
        $neighbors = [];
        for ($f = -1; $f <= 1; $f++) {
            for ($r = -1; $r <= 1; $r++) {
                if ($f == 0 && $r == 0) continue;
                $newFile = chr(ord($file) + $f);
                $newRank = $rank + $r;
                if ($this->isValidSquare($newFile, $newRank)) {
                    $neighbors[] = ['file' => $newFile, 'rank' => $newRank];
                }
            }
        }
        return $neighbors;
    }
}