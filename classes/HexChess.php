<?php
// classes/HexChess.php - Updated to extend BaseChess with fog of war and shogi drops

require_once 'BaseChess.php';

class HexChess extends BaseChess {
    use HexagonalMovement;
    
    public $board;
    protected $boardSize;  // ADD THIS LINE
    private $players;
    private $activePlayers;
    private $playerUsers; // Maps player slots to user IDs
    
    public function __construct($gameId = null, $playerCount = 2, $boardSize = 8) {
        // Define player colors for up to 6 players
        $this->players = ['red', 'purple', 'blue', 'green', 'yellow', 'orange'];
        $this->playerUsers = array_fill(0, max(2, min(6, $playerCount)), null);
        $this->activePlayers = array_fill(0, max(2, min(6, $playerCount)), true);
        
        // Call parent constructor
        parent::__construct($gameId, $playerCount, $boardSize);
        
        // Limit player colors to actual player count
        $this->players = array_slice($this->players, 0, $this->playerCount);
    }
    
    // === REQUIRED ABSTRACT METHOD IMPLEMENTATIONS ===
    
    protected function initializeBoard($boardSize) {
        $this->boardSize = max(4, min(8, $boardSize ?? 8));
        $this->board = [];
        
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize); 
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                $this->board[$q][$r] = null;
            }
        }
        
        $this->setupPieces();
    }
    
    public function getBoard() {
        return $this->board;
    }
    
    public function movePiece($fromQ, $fromR, $toQ, $toR) {
        if (!$this->isValidMove($fromQ, $fromR, $toQ, $toR)) {
            return false;
        }
        
        $piece = $this->board[$fromQ][$fromR];
        $capturedPiece = $this->board[$toQ][$toR];
        
        // Handle captured pieces for shogi drops
        if ($capturedPiece && $this->isShogiDropsEnabled()) {
            $this->capturePiece($capturedPiece);
        }
        
        $this->board[$toQ][$toR] = $piece;
        $this->board[$fromQ][$fromR] = null;
        
        $this->gameState['moveCount']++;
        $this->checkForEliminations();
        $this->advanceToNextActivePlayer();
        
        return true;
    }
    
    public function getValidMoves($fromQ, $fromR) {
        $piece = $this->getPiece($fromQ, $fromR);
        
        if (!$piece || $piece->player !== $this->currentPlayer) {
            return [];
        }
        
        $validMoves = [];
        
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize); 
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                
                if ($q == $fromQ && $r == $fromR) continue;
                if (!$this->isValidHex($q, $r)) continue;
                
                if ($this->isValidMoveForPlayer($fromQ, $fromR, $q, $r, $piece->player)) {
                    $targetPiece = $this->getPiece($q, $r);
                    $validMoves[] = [
                        'q' => $q, 
                        'r' => $r,
                        'type' => $targetPiece ? 'capture' : 'move'
                    ];
                }
            }
        }
        
        return $validMoves;
    }
    
    public function isGameOver() {
        $activePlayerCount = $this->getActivePlayerCount();
        return $activePlayerCount <= 1;
    }
    
    public function getCurrentPlayerSlot() {
        return $this->currentPlayer;
    }
    
    public function getPiece($q, $r) {
        return $this->isValidHex($q, $r) ? ($this->board[$q][$r] ?? null) : null;
    }
    
    // === FOG OF WAR IMPLEMENTATION ===
    
    protected function getAllPiecePositions() {
        $positions = [];
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize); 
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                if ($this->getPiece($q, $r) !== null) {
                    $positions[] = ['q' => $q, 'r' => $r];
                }
            }
        }
        return $positions;
    }
    
    protected function getPlayerPieces($playerSlot) {
        $pieces = [];
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize); 
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                $piece = $this->getPiece($q, $r);
                if ($piece && $piece->player === $playerSlot) {
                    $pieces[] = ['q' => $q, 'r' => $r, 'piece' => $piece];
                }
            }
        }
        return $pieces;
    }
    
    protected function getAdjacentPositions($q, $r) {
        return $this->getHexNeighbors($q, $r);
    }
    
    // === SHOGI DROPS IMPLEMENTATION ===
    
    protected function isValidDropPosition($playerSlot, $pieceType, $q, $r) {
        // Basic hex validity check
        if (!$this->isValidHex($q, $r)) {
            return false;
        }
        
        // Position must be empty
        if ($this->getPiece($q, $r) !== null) {
            return false;
        }
        
        // Hex-specific drop rule for pawns
        if ($pieceType === 'pawn') {
            return $this->isValidPawnDropPosition($playerSlot, $q, $r);
        }
        
        return true;
    }

    private function isValidPawnDropPosition($playerSlot, $q, $r) {
        // Place a temporary pawn to test for immediate checkmate
        $tempPawn = new Piece('pawn', $playerSlot);
        $this->board[$q][$r] = $tempPawn;
        
        $wouldCauseCheckmate = false;
        for ($enemyPlayer = 0; $enemyPlayer < $this->playerCount; $enemyPlayer++) {
            if ($enemyPlayer !== $playerSlot && $this->activePlayers[$enemyPlayer]) {
                if ($this->isCheckmate($enemyPlayer)) {
                    $wouldCauseCheckmate = true;
                    break;
                }
            }
        }
        
        // Remove temporary pawn
        $this->board[$q][$r] = null;
        
        return !$wouldCauseCheckmate;
    }
    
    public function dropPiece($playerSlot, $pieceType, $q, $r) {
        if (!$this->canDropPiece($playerSlot, $pieceType, $q, $r)) {
            return false;
        }
        
        // Remove piece from captured pieces
        $removed = false;
        for ($i = 0; $i < count($this->capturedPieces); $i++) {
            $capturedPiece = $this->capturedPieces[$i];
            if ($capturedPiece['captured_by'] === $playerSlot && 
                $capturedPiece['type'] === $pieceType && !$removed) {
                array_splice($this->capturedPieces, $i, 1);
                $removed = true;
                break;
            }
        }
        
        if (!$removed) {
            return false;
        }
        
        // Place the piece
        $this->board[$q][$r] = new Piece($pieceType, $playerSlot);
        
        // Advance turn
        $this->gameState['moveCount']++;
        $this->advanceToNextActivePlayer();
        
        return true;
    }

    // --- FAST MOVE GENERATORS (skip self-check test) ---

    private function isValidMoveForPlayerNoSelfCheck($fromQ, $fromR, $toQ, $toR, $player) {
        $piece = $this->getPiece($fromQ, $fromR);
        if (!$piece || $piece->player !== $player) return false;
        if (!$this->isValidHex($toQ, $toR)) return false;
        if ($fromQ === $toQ && $fromR === $toR) return false;

        $targetPiece = $this->getPiece($toQ, $toR);
        if ($targetPiece && $targetPiece->player === $player) return false;

        // IMPORTANT: we deliberately skip wouldLeaveKingInCheck() here
        return $this->canPieceMoveTo($piece, $fromQ, $fromR, $toQ, $toR);
    }

    private function getValidMovesNoSelfCheck($fromQ, $fromR, $player) {
        $piece = $this->getPiece($fromQ, $fromR);
        if (!$piece || $piece->player !== $player) return [];

        $validMoves = [];
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize);
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {

                if ($q == $fromQ && $r == $fromR) continue;
                if (!$this->isValidHex($q, $r)) continue;

                if ($this->isValidMoveForPlayerNoSelfCheck($fromQ, $fromR, $q, $r, $player)) {
                    $validMoves[] = ['q' => $q, 'r' => $r];
                }
            }
        }
        return $validMoves;
    }

    /**
     * Fast, bounded move list for the **current** player (AI uses this).
     * Skips king-safety during generation; caller must validate the final move.
     */
    public function getAllPseudoLegalMovesForCurrentPlayer(int $limit = 80, int $deadlineMs = 200) {
        $start = microtime(true);
        $deadline = $start + ($deadlineMs / 1000.0);

        $moves = [];
        $player = $this->currentPlayer;

        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize);
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {

                if (microtime(true) >= $deadline) return $moves;

                $piece = $this->getPiece($q, $r);
                if (!$piece || $piece->player !== $player) continue;

                $v = $this->getValidMovesNoSelfCheck($q, $r, $player);
                foreach ($v as $m) {
                    $moves[] = ['fromQ' => $q, 'fromR' => $r, 'toQ' => $m['q'], 'toR' => $m['r']];
                    if (count($moves) >= $limit || microtime(true) >= $deadline) {
                        return $moves;
                    }
                }
            }
        }

        return $moves;
    }
    
    // === SERIALIZATION ===
    
    protected function getVariantSpecificData() {
        return [
            'board' => $this->board,
            'players' => $this->players,
            'activePlayers' => $this->activePlayers,
            'playerUsers' => $this->playerUsers,
            'boardSize' => $this->boardSize
        ];
    }
    
    protected function restoreVariantSpecificData($data) {
        $this->board = $data['board'] ?? [];
        $this->players = $data['players'] ?? ['red', 'blue'];
        $this->activePlayers = $data['activePlayers'] ?? array_fill(0, $this->playerCount, true);
        $this->playerUsers = $data['playerUsers'] ?? array_fill(0, $this->playerCount, null);
        $this->boardSize = $data['boardSize'] ?? 8;
    }
    
    // === ENHANCED GAME STATE ===
    
    public function getGameState() {
        $baseState = parent::getGameState();
        
        // Find kings in check for highlighting
        $kingsInCheck = [];
        for ($player = 0; $player < $this->playerCount; $player++) {
            if ($this->activePlayers[$player]) {
                $kingPos = $this->findKing($player);
                if ($kingPos && $this->isKingInCheck($player)) {
                    $kingsInCheck[] = $kingPos;
                }
            }
        }
        
        // Check for game over conditions
        $activePlayerCount = $this->getActivePlayerCount();
        $gameOver = $activePlayerCount <= 1;
        $winner = null;
        $reason = null;
        
        if ($gameOver && $activePlayerCount == 1) {
            // Find the last active player as winner
            for ($player = 0; $player < $this->playerCount; $player++) {
                if ($this->activePlayers[$player]) {
                    $winner = $this->playerUsers[$player];
                    $reason = 'elimination';
                    break;
                }
            }
        } elseif ($gameOver && $activePlayerCount == 0) {
            // All players resigned or eliminated - no winner
            $winner = null;
            $reason = 'resignation';
        }
        
        // Override base state with hex-specific data
        $baseState['gameStatus'] = [
            'gameOver' => $gameOver,
            'winner' => $winner,
            'reason' => $reason,
            'activePlayerCount' => $activePlayerCount
        ];
        
        $baseState['kingsInCheck'] = $kingsInCheck;
        $baseState['activePlayers'] = $this->activePlayers;
        $baseState['playerUsers'] = $this->playerUsers;
        $baseState['isInCheck'] = $this->activePlayers[$this->currentPlayer] ? $this->isKingInCheck($this->currentPlayer) : false;
        
        return $baseState;
    }
    
    // === EXISTING HEXCHESS METHODS (Preserved for compatibility) ===
    
    public function getBoardSize() {
        return $this->boardSize;
    }
    
    public function setPlayerUsers($playerUsers) {
        $this->playerUsers = $playerUsers;
    }
    
    public function getPlayerUsers() {
        return $this->playerUsers;
    }
    
    public function canUserMove($userId) {
        return $this->playerUsers[$this->currentPlayer] == $userId;
    }
    
    // Resign functionality
    public function resignPlayer($playerSlot) {
        // Validate player slot
        if ($playerSlot < 0 || $playerSlot >= $this->playerCount) {
            return "Invalid player slot";
        }
        
        // Check if player is already inactive
        if (!$this->activePlayers[$playerSlot]) {
            return "Player already inactive";
        }
        
        // Mark player as inactive
        $this->activePlayers[$playerSlot] = false;
        
        // If it was the current player's turn, advance to next active player
        if ($this->currentPlayer === $playerSlot) {
            $this->advanceToNextActivePlayer();
        }
        
        // Update game state if game is over
        if ($this->getActivePlayerCount() <= 1) {
            $this->endGame($this->getLastActivePlayer(), 'resignation');
        }
        
        // Log the resignation
        error_log("Player $playerSlot ({$this->players[$playerSlot]}) has resigned from game {$this->gameId}");
        
        return true;
    }
    
    private function getLastActivePlayer() {
        for ($player = 0; $player < $this->playerCount; $player++) {
            if ($this->activePlayers[$player]) {
                return $this->playerUsers[$player];
            }
        }
        return null;
    }
    
    public function getActivePlayerCount() {
        return array_sum($this->activePlayers);
    }
    
    public function isPlayerActive($playerSlot) {
        return isset($this->activePlayers[$playerSlot]) ? $this->activePlayers[$playerSlot] : false;
    }
    
    public function getActivePlayers() {
        return $this->activePlayers;
    }
    
    private function setupPieces() {
        switch ($this->playerCount) {
            case 2:
                $this->setupTwoPlayerPieces();
                break;
            case 3:
                $this->setupThreePlayerPieces();
                break;
            case 4:
                $this->setupFourPlayerPieces();
                break;
            case 5:
                $this->setupFivePlayerPieces();
                break;
            case 6:
                $this->setupSixPlayerPieces();
                break;
            default:
                $this->setupTwoPlayerPieces();
        }
    }
    
    // [All your existing setup methods remain the same]
    private function setupTwoPlayerPieces() {
        // Player 1 (Red) - left edge
        $this->placePiece(-$this->boardSize, 0, new Piece('king', 0));
        $this->placePiece(-$this->boardSize+1, -1, new Piece('rook', 0));
        $this->placePiece(-$this->boardSize+2, -2, new Piece('knight', 0));
        $this->placePiece(-$this->boardSize+3, -3, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize, 1, new Piece('rook', 0));
        $this->placePiece(-$this->boardSize+1, 0, new Piece('queen', 0));
        $this->placePiece(-$this->boardSize+2, -1, new Piece('bishop', 0));
        $this->placePiece(-$this->boardSize+3, -2, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize, 2, new Piece('bishop', 0));
        $this->placePiece(-$this->boardSize+1, 1, new Piece('knight', 0));
        $this->placePiece(-$this->boardSize+2, 0, new Piece('bishop', 0));
        $this->placePiece(-$this->boardSize+3, -1, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize, 3, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize+1, 2, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize+2, 1, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize+3, 0, new Piece('pawn', 0));
        
        // Player 2 (Blue) - right edge
        $this->placePiece($this->boardSize, 0, new Piece('king', 1));
        $this->placePiece($this->boardSize-1, 1, new Piece('rook', 1));
        $this->placePiece($this->boardSize-2, 2, new Piece('knight', 1));
        $this->placePiece($this->boardSize-3, 3, new Piece('pawn', 1));
        $this->placePiece($this->boardSize, -1, new Piece('rook', 1));
        $this->placePiece($this->boardSize-1, 0, new Piece('queen', 1));
        $this->placePiece($this->boardSize-2, 1, new Piece('bishop', 1));
        $this->placePiece($this->boardSize-3, 2, new Piece('pawn', 1));
        $this->placePiece($this->boardSize, -2, new Piece('bishop', 1));
        $this->placePiece($this->boardSize-1, -1, new Piece('knight', 1));
        $this->placePiece($this->boardSize-2, 0, new Piece('bishop', 1));
        $this->placePiece($this->boardSize-3, 1, new Piece('pawn', 1));
        $this->placePiece($this->boardSize, -3, new Piece('pawn', 1));
        $this->placePiece($this->boardSize-1, -2, new Piece('pawn', 1));
        $this->placePiece($this->boardSize-2, -1, new Piece('pawn', 1));
        $this->placePiece($this->boardSize-3, 0, new Piece('pawn', 1));
    }
    
    private function setupThreePlayerPieces() {
        // Player 1 (Red) - left edge
        $this->placePiece(-$this->boardSize, 0, new Piece('king', 0));
        $this->placePiece(-$this->boardSize+1, -1, new Piece('rook', 0));
        $this->placePiece(-$this->boardSize+2, -2, new Piece('knight', 0));
        $this->placePiece(-$this->boardSize+3, -3, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize, 1, new Piece('rook', 0));
        $this->placePiece(-$this->boardSize+1, 0, new Piece('queen', 0));
        $this->placePiece(-$this->boardSize+2, -1, new Piece('bishop', 0));
        $this->placePiece(-$this->boardSize+3, -2, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize, 2, new Piece('bishop', 0));
        $this->placePiece(-$this->boardSize+1, 1, new Piece('knight', 0));
        $this->placePiece(-$this->boardSize+2, 0, new Piece('bishop', 0));
        $this->placePiece(-$this->boardSize+3, -1, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize, 3, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize+1, 2, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize+2, 1, new Piece('pawn', 0));
        $this->placePiece(-$this->boardSize+3, 0, new Piece('pawn', 0));
        
        // Player 2 (Blue) - top-right corner
        $this->placePiece($this->boardSize, -$this->boardSize, new Piece('king', 1));
        $this->placePiece($this->boardSize-1, -$this->boardSize+1, new Piece('queen', 1));
        $this->placePiece($this->boardSize-1, -$this->boardSize, new Piece('rook', 1));
        $this->placePiece($this->boardSize, -$this->boardSize+1, new Piece('rook', 1));
        $this->placePiece($this->boardSize-2, -$this->boardSize+2, new Piece('bishop', 1));
        $this->placePiece($this->boardSize-2, -$this->boardSize+1, new Piece('knight', 1));
        $this->placePiece($this->boardSize-2, -$this->boardSize, new Piece('bishop', 1));
        $this->placePiece($this->boardSize-1, -$this->boardSize+2, new Piece('bishop', 1));
        $this->placePiece($this->boardSize, -$this->boardSize+2, new Piece('knight', 1));
        $this->placePiece($this->boardSize-3, -$this->boardSize+3, new Piece('pawn', 1));
        $this->placePiece($this->boardSize-3, -$this->boardSize+2, new Piece('pawn', 1));
        $this->placePiece($this->boardSize-3, -$this->boardSize+1, new Piece('pawn', 1));
        $this->placePiece($this->boardSize-3, -$this->boardSize, new Piece('pawn', 1));
        $this->placePiece($this->boardSize-2, -$this->boardSize+3, new Piece('pawn', 1));
        $this->placePiece($this->boardSize-1, -$this->boardSize+3, new Piece('pawn', 1));
        $this->placePiece($this->boardSize, -$this->boardSize+3, new Piece('pawn', 1));
        
        // Player 3 (Green) - bottom corner
        $this->placePiece(0, $this->boardSize, new Piece('king', 2));
        $this->placePiece(1, $this->boardSize-1, new Piece('rook', 2));
        $this->placePiece(2, $this->boardSize-2, new Piece('knight', 2));
        $this->placePiece(3, $this->boardSize-3, new Piece('pawn', 2));
        $this->placePiece(-1, $this->boardSize, new Piece('rook', 2));
        $this->placePiece(0, $this->boardSize-1, new Piece('queen', 2));
        $this->placePiece(1, $this->boardSize-2, new Piece('bishop', 2));
        $this->placePiece(2, $this->boardSize-3, new Piece('pawn', 2));
        $this->placePiece(-2, $this->boardSize, new Piece('bishop', 2));
        $this->placePiece(-1, $this->boardSize-1, new Piece('knight', 2));
        $this->placePiece(0, $this->boardSize-2, new Piece('bishop', 2));
        $this->placePiece(1, $this->boardSize-3, new Piece('pawn', 2));
        $this->placePiece(-3, $this->boardSize, new Piece('pawn', 2));
        $this->placePiece(-2, $this->boardSize-1, new Piece('pawn', 2));
        $this->placePiece(-1, $this->boardSize-2, new Piece('pawn', 2));
        $this->placePiece(0, $this->boardSize-3, new Piece('pawn', 2));
    }

    // 4 players
    private function setupFourPlayerPieces() {
        $positions = [
            // Player 0 (Red) - West
            0 => [
                'king' => [-$this->boardSize, 0],
                'queen' => [-$this->boardSize+1, 0],
                'rook1' => [-$this->boardSize+1, -1],
                'rook2' => [-$this->boardSize, 1],
                'bishop1' => [-$this->boardSize+2, -1],
                'bishop2' => [-$this->boardSize, 2],
                'bishop3' => [-$this->boardSize+2, 0],
                'knight1' => [-$this->boardSize+2, -2],
                'knight2' => [-$this->boardSize+1, 1],
                'pawns' => [
                    [-$this->boardSize+3, -3], [-$this->boardSize+3, -2], [-$this->boardSize+3, -1],
                    [-$this->boardSize+3, 0], [-$this->boardSize, 3], [-$this->boardSize+1, 2],
                    [-$this->boardSize+2, 1]
                ]
            ],
            
            // Player 1 (Green) - Northeast
            1 => [
                'king' => [$this->boardSize, -$this->boardSize],
                'queen' => [$this->boardSize-1, -$this->boardSize+1],
                'rook1' => [$this->boardSize-1, -$this->boardSize],
                'rook2' => [$this->boardSize, -$this->boardSize+1],
                'bishop1' => [$this->boardSize-2, -$this->boardSize+1],
                'bishop2' => [$this->boardSize-2, -$this->boardSize],
                'bishop3' => [$this->boardSize-1, -$this->boardSize+2],
                'knight1' => [$this->boardSize-2, -$this->boardSize+2],
                'knight2' => [$this->boardSize, -$this->boardSize+2],
                'pawns' => [
                    [$this->boardSize-3, -$this->boardSize+3], [$this->boardSize-3, -$this->boardSize+2],
                    [$this->boardSize-3, -$this->boardSize+1], [$this->boardSize-3, -$this->boardSize],
                    [$this->boardSize-2, -$this->boardSize+3], [$this->boardSize-1, -$this->boardSize+3],
                    [$this->boardSize, -$this->boardSize+3]
                ]
            ],
            
            // Player 2 (Yellow) - East
            2 => [
                'king' => [$this->boardSize, 0],
                'queen' => [$this->boardSize-1, 0],
                'rook1' => [$this->boardSize-1, 1],
                'rook2' => [$this->boardSize, -1],
                'bishop1' => [$this->boardSize-2, 1],
                'bishop2' => [$this->boardSize, -2],
                'bishop3' => [$this->boardSize-2, 0],
                'knight1' => [$this->boardSize-2, 2],
                'knight2' => [$this->boardSize-1, -1],
                'pawns' => [
                    [$this->boardSize-3, 3], [$this->boardSize-3, 2], [$this->boardSize-3, 1],
                    [$this->boardSize-3, 0], [$this->boardSize, -3], [$this->boardSize-1, -2],
                    [$this->boardSize-2, -1]
                ]
            ],
            
            // Player 3 (Orange) - Southwest
            3 => [
                'king' => [-$this->boardSize, $this->boardSize],
                'queen' => [-$this->boardSize+1, $this->boardSize-1],
                'rook1' => [-$this->boardSize+1, $this->boardSize],
                'rook2' => [-$this->boardSize, $this->boardSize-1],
                'bishop1' => [-$this->boardSize+2, $this->boardSize-1],
                'bishop2' => [-$this->boardSize+2, $this->boardSize],
                'bishop3' => [-$this->boardSize+1, $this->boardSize-2],
                'knight1' => [-$this->boardSize+2, $this->boardSize-2],
                'knight2' => [-$this->boardSize, $this->boardSize-2],
                'pawns' => [
                    [-$this->boardSize+3, $this->boardSize-3], [-$this->boardSize+3, $this->boardSize-2],
                    [-$this->boardSize+3, $this->boardSize-1], [-$this->boardSize+3, $this->boardSize],
                    [-$this->boardSize+2, $this->boardSize-3], [-$this->boardSize+1, $this->boardSize-3],
                    [-$this->boardSize, $this->boardSize-3]
                ]
            ]
        ];
        
        $this->placePiecesFromPositions($positions);
    }

    // 5 players
    private function setupFivePlayerPieces() {
        $positions = [
            // Player 0 (Red) - West
            0 => [
                'king' => [-$this->boardSize, 0],
                'queen' => [-$this->boardSize+1, 0],
                'rook1' => [-$this->boardSize+1, -1],
                'rook2' => [-$this->boardSize, 1],
                'bishop1' => [-$this->boardSize+2, -1],
                'bishop2' => [-$this->boardSize, 2],
                'bishop3' => [-$this->boardSize+2, 0],
                'knight1' => [-$this->boardSize+2, -2],
                'knight2' => [-$this->boardSize+1, 1],
                'pawns' => [
                    [-$this->boardSize+3, -3], [-$this->boardSize+3, -2], [-$this->boardSize+3, -1],
                    [-$this->boardSize+3, 0], [-$this->boardSize, 3], [-$this->boardSize+1, 2],
                    [-$this->boardSize+2, 1]
                ]
            ],
            
            // Player 1 (Blue) - Northwest
            1 => [
                'king' => [0, -$this->boardSize],
                'queen' => [0, -$this->boardSize+1],
                'rook1' => [-1, -$this->boardSize+1],
                'rook2' => [1, -$this->boardSize],
                'bishop1' => [-1, -$this->boardSize+2],
                'bishop2' => [2, -$this->boardSize],
                'bishop3' => [0, -$this->boardSize+2],
                'knight1' => [-2, -$this->boardSize+2],
                'knight2' => [1, -$this->boardSize+1],
                'pawns' => [
                    [-3, -$this->boardSize+3], [-2, -$this->boardSize+3], [-1, -$this->boardSize+3],
                    [0, -$this->boardSize+3], [3, -$this->boardSize], [2, -$this->boardSize+1],
                    [1, -$this->boardSize+2]
                ]
            ],
            
            // Player 2 (Green) - Northeast
            2 => [
                'king' => [$this->boardSize, -$this->boardSize],
                'queen' => [$this->boardSize-1, -$this->boardSize+1],
                'rook1' => [$this->boardSize-1, -$this->boardSize],
                'rook2' => [$this->boardSize, -$this->boardSize+1],
                'bishop1' => [$this->boardSize-2, -$this->boardSize+1],
                'bishop2' => [$this->boardSize-2, -$this->boardSize],
                'bishop3' => [$this->boardSize-1, -$this->boardSize+2],
                'knight1' => [$this->boardSize-2, -$this->boardSize+2],
                'knight2' => [$this->boardSize, -$this->boardSize+2],
                'pawns' => [
                    [$this->boardSize-3, -$this->boardSize+3], [$this->boardSize-3, -$this->boardSize+2],
                    [$this->boardSize-3, -$this->boardSize+1], [$this->boardSize-3, -$this->boardSize],
                    [$this->boardSize-2, -$this->boardSize+3], [$this->boardSize-1, -$this->boardSize+3],
                    [$this->boardSize, -$this->boardSize+3]
                ]
            ],
            
            // Player 3 (Yellow) - East
            3 => [
                'king' => [$this->boardSize, 0],
                'queen' => [$this->boardSize-1, 0],
                'rook1' => [$this->boardSize-1, 1],
                'rook2' => [$this->boardSize, -1],
                'bishop1' => [$this->boardSize-2, 1],
                'bishop2' => [$this->boardSize, -2],
                'bishop3' => [$this->boardSize-2, 0],
                'knight1' => [$this->boardSize-2, 2],
                'knight2' => [$this->boardSize-1, -1],
                'pawns' => [
                    [$this->boardSize-3, 3], [$this->boardSize-3, 2], [$this->boardSize-3, 1],
                    [$this->boardSize-3, 0], [$this->boardSize, -3], [$this->boardSize-1, -2],
                    [$this->boardSize-2, -1]
                ]
            ],
            
            // Player 4 (Purple) - Southeast
            4 => [
                'king' => [0, $this->boardSize],
                'queen' => [0, $this->boardSize-1],
                'rook1' => [1, $this->boardSize-1],
                'rook2' => [-1, $this->boardSize],
                'bishop1' => [1, $this->boardSize-2],
                'bishop2' => [-2, $this->boardSize],
                'bishop3' => [0, $this->boardSize-2],
                'knight1' => [2, $this->boardSize-2],
                'knight2' => [-1, $this->boardSize-1],
                'pawns' => [
                    [3, $this->boardSize-3], [2, $this->boardSize-3], [1, $this->boardSize-3],
                    [0, $this->boardSize-3], [-3, $this->boardSize], [-2, $this->boardSize-1],
                    [-1, $this->boardSize-2]
                ]
            ]
        ];
        
        $this->placePiecesFromPositions($positions);
    }

    // 6 players
    private function setupSixPlayerPieces() {
        $positions = [
            // Player 0 (Red) - West edge
            0 => [
                'king' => [-$this->boardSize, 0],
                'queen' => [-$this->boardSize+1, 0],
                'rook1' => [-$this->boardSize+1, -1],
                'rook2' => [-$this->boardSize, 1],
                'bishop1' => [-$this->boardSize+2, -1],
                'bishop2' => [-$this->boardSize, 2],
                'bishop3' => [-$this->boardSize+2, 0],
                'knight1' => [-$this->boardSize+2, -2],
                'knight2' => [-$this->boardSize+1, 1],
                'pawns' => [
                    [-$this->boardSize+3, -3], [-$this->boardSize+3, -2], [-$this->boardSize+3, -1],
                    [-$this->boardSize+3, 0], [-$this->boardSize, 3], [-$this->boardSize+1, 2],
                    [-$this->boardSize+2, 1]
                ]
            ],
            
            // Player 1 (Purple) - Northwest corner
            1 => [
                'king' => [0, -$this->boardSize],
                'queen' => [0, -$this->boardSize+1],
                'rook1' => [-1, -$this->boardSize+1],
                'rook2' => [1, -$this->boardSize],
                'bishop1' => [-1, -$this->boardSize+2],
                'bishop2' => [2, -$this->boardSize],
                'bishop3' => [0, -$this->boardSize+2],
                'knight1' => [-2, -$this->boardSize+2],
                'knight2' => [1, -$this->boardSize+1],
                'pawns' => [
                    [-3, -$this->boardSize+3], [-2, -$this->boardSize+3], [-1, -$this->boardSize+3],
                    [0, -$this->boardSize+3], [3, -$this->boardSize], [2, -$this->boardSize+1],
                    [1, -$this->boardSize+2]
                ]
            ],
            
            // Player 2 (Blue) - Northeast corner
            2 => [
                'king' => [$this->boardSize, -$this->boardSize],
                'queen' => [$this->boardSize-1, -$this->boardSize+1],
                'rook1' => [$this->boardSize-1, -$this->boardSize],
                'rook2' => [$this->boardSize, -$this->boardSize+1],
                'bishop1' => [$this->boardSize-2, -$this->boardSize+1],
                'bishop2' => [$this->boardSize-2, -$this->boardSize],
                'bishop3' => [$this->boardSize-1, -$this->boardSize+2],
                'knight1' => [$this->boardSize-2, -$this->boardSize+2],
                'knight2' => [$this->boardSize, -$this->boardSize+2],
                'pawns' => [
                    [$this->boardSize-3, -$this->boardSize+3], [$this->boardSize-3, -$this->boardSize+2],
                    [$this->boardSize-3, -$this->boardSize+1], [$this->boardSize-3, -$this->boardSize],
                    [$this->boardSize-2, -$this->boardSize+3], [$this->boardSize-1, -$this->boardSize+3],
                    [$this->boardSize, -$this->boardSize+3]
                ]
            ],
            
            // Player 3 (Green) - East edge
            3 => [
                'king' => [$this->boardSize, 0],
                'queen' => [$this->boardSize-1, 0],
                'rook1' => [$this->boardSize-1, 1],
                'rook2' => [$this->boardSize, -1],
                'bishop1' => [$this->boardSize-2, 1],
                'bishop2' => [$this->boardSize, -2],
                'bishop3' => [$this->boardSize-2, 0],
                'knight1' => [$this->boardSize-2, 2],
                'knight2' => [$this->boardSize-1, -1],
                'pawns' => [
                    [$this->boardSize-3, 3], [$this->boardSize-3, 2], [$this->boardSize-3, 1],
                    [$this->boardSize-3, 0], [$this->boardSize, -3], [$this->boardSize-1, -2],
                    [$this->boardSize-2, -1]
                ]
            ],
            
            // Player 4 (Yellow) - Southeast corner
            4 => [
                'king' => [0, $this->boardSize],
                'queen' => [0, $this->boardSize-1],
                'rook1' => [1, $this->boardSize-1],
                'rook2' => [-1, $this->boardSize],
                'bishop1' => [1, $this->boardSize-2],
                'bishop2' => [-2, $this->boardSize],
                'bishop3' => [0, $this->boardSize-2],
                'knight1' => [2, $this->boardSize-2],
                'knight2' => [-1, $this->boardSize-1],
                'pawns' => [
                    [3, $this->boardSize-3], [2, $this->boardSize-3], [1, $this->boardSize-3],
                    [0, $this->boardSize-3], [-3, $this->boardSize], [-2, $this->boardSize-1],
                    [-1, $this->boardSize-2]
                ]
            ],
            
            // Player 5 (Orange) - Southwest corner
            5 => [
                'king' => [-$this->boardSize, $this->boardSize],
                'queen' => [-$this->boardSize+1, $this->boardSize-1],
                'rook1' => [-$this->boardSize+1, $this->boardSize],
                'rook2' => [-$this->boardSize, $this->boardSize-1],
                'bishop1' => [-$this->boardSize+2, $this->boardSize-1],
                'bishop2' => [-$this->boardSize+2, $this->boardSize],
                'bishop3' => [-$this->boardSize+1, $this->boardSize-2],
                'knight1' => [-$this->boardSize+2, $this->boardSize-2],
                'knight2' => [-$this->boardSize, $this->boardSize-2],
                'pawns' => [
                    [-$this->boardSize+3, $this->boardSize-3], [-$this->boardSize+3, $this->boardSize-2],
                    [-$this->boardSize+3, $this->boardSize-1], [-$this->boardSize+3, $this->boardSize],
                    [-$this->boardSize+2, $this->boardSize-3], [-$this->boardSize+1, $this->boardSize-3],
                    [-$this->boardSize, $this->boardSize-3]
                ]
            ]
        ];
        
        $this->placePiecesFromPositions($positions);
    }

    private function placePiecesFromPositions($positions) {
        foreach ($positions as $player => $pieces) {
            // Place major pieces
            $this->placePiece($pieces['king'][0], $pieces['king'][1], new Piece('king', $player));
            $this->placePiece($pieces['queen'][0], $pieces['queen'][1], new Piece('queen', $player));
            $this->placePiece($pieces['rook1'][0], $pieces['rook1'][1], new Piece('rook', $player));
            $this->placePiece($pieces['rook2'][0], $pieces['rook2'][1], new Piece('rook', $player));
            $this->placePiece($pieces['bishop1'][0], $pieces['bishop1'][1], new Piece('bishop', $player));
            $this->placePiece($pieces['bishop2'][0], $pieces['bishop2'][1], new Piece('bishop', $player));
            $this->placePiece($pieces['bishop3'][0], $pieces['bishop3'][1], new Piece('bishop', $player));
            $this->placePiece($pieces['knight1'][0], $pieces['knight1'][1], new Piece('knight', $player));
            $this->placePiece($pieces['knight2'][0], $pieces['knight2'][1], new Piece('knight', $player));
            
            // Place pawns
            foreach ($pieces['pawns'] as $pawnPos) {
                $this->placePiece($pawnPos[0], $pawnPos[1], new Piece('pawn', $player));
            }
        }
    }
    
    private function placePiece($q, $r, $piece) {
        if ($this->isValidHex($q, $r)) {
            $this->board[$q][$r] = $piece;
        }
    }
    
    // [All your remaining existing methods - path checking, movement validation, etc.]
    private function isPathClear($fromQ, $fromR, $toQ, $toR) {
        $dq = $toQ - $fromQ;
        $dr = $toR - $fromR;
        
        $gcd = $this->gcd(abs($dq), abs($dr));
        if ($gcd == 0) return true;
        
        $stepQ = $dq / $gcd;
        $stepR = $dr / $gcd;
        
        for ($step = 1; $step < $gcd; $step++) {
            $checkQ = $fromQ + $stepQ * $step;
            $checkR = $fromR + $stepR * $step;
            
            if (!$this->isValidHex($checkQ, $checkR)) {
                return false;
            }
            
            if ($this->getPiece($checkQ, $checkR) !== null) {
                return false;
            }
        }
        
        return true;
    }
    
    private function gcd($a, $b) {
        if ($a == 0 && $b == 0) return 0;
        if ($a == 0) return $b;
        if ($b == 0) return $a;
        
        while ($b != 0) {
            $temp = $b;
            $b = $a % $b;
            $a = $temp;
        }
        return $a;
    }
    
    // Movement pattern detection
    private function isOrthogonalMove($dq, $dr) {
        $ds = -$dq - $dr;
        return ($dr == 0) || ($dq == 0) || ($dq == -$dr);
    }
    
    private function isDiagonalMove($dq, $dr) {
        $ds = -$dq - $dr;
        return ($dq == $dr) || ($dr == $ds) || ($dq == $ds);
    }
    
    private function advanceToNextActivePlayer() {
        $attempts = 0;
        do {
            $this->currentPlayer = ($this->currentPlayer + 1) % $this->playerCount;
            $attempts++;
            
            if ($attempts > $this->playerCount) {
                error_log("Warning: Could not find active player in game {$this->gameId}");
                break;
            }
        } while (!$this->activePlayers[$this->currentPlayer] && $this->getActivePlayerCount() > 1);
    }
    
    private function checkForEliminations() {
        for ($player = 0; $player < $this->playerCount; $player++) {
            if ($this->activePlayers[$player] && $this->isCheckmate($player)) {
                $this->activePlayers[$player] = false;
            }
        }
    }
    
    // Move validation with detailed error checking
    private function isValidMove($fromQ, $fromR, $toQ, $toR) {
        $piece = $this->getPiece($fromQ, $fromR);
        
        if (!$piece) return false;
        if ($piece->player !== $this->currentPlayer || !$this->activePlayers[$this->currentPlayer]) return false;
        if (!$this->isValidHex($toQ, $toR)) return false;
        if ($fromQ === $toQ && $fromR === $toR) return false;
        
        $targetPiece = $this->getPiece($toQ, $toR);
        if ($targetPiece && $targetPiece->player === $this->currentPlayer) return false;
        
        if (!$this->canPieceMoveTo($piece, $fromQ, $fromR, $toQ, $toR)) return false;
        
        if ($this->gameState['moveCount'] >= 2) {
            if ($this->wouldLeaveKingInCheck($fromQ, $fromR, $toQ, $toR, $this->currentPlayer)) {
                return false;
            }
        }
        
        return true;
    }
    
    // Piece movement logic
    private function canPieceMoveTo($piece, $fromQ, $fromR, $toQ, $toR) {
        $dq = $toQ - $fromQ;
        $dr = $toR - $fromR;
        
        switch ($piece->type) {
            case 'pawn':
                return $this->canPawnMove($piece, $fromQ, $fromR, $toQ, $toR);
                
            case 'rook':
                return $this->isOrthogonalMove($dq, $dr) && $this->isPathClear($fromQ, $fromR, $toQ, $toR);
                
            case 'bishop':
                return $this->isDiagonalMove($dq, $dr) && $this->isPathClear($fromQ, $fromR, $toQ, $toR);
                
            case 'knight':
                $knightMoves = [
                    [2, 1], [3, -1], [1, 2], [-1, 3], [-2, 3], [-3, 2],
                    [-3, 1], [-2, -1], [-1, -2], [1, -3], [2, -3], [3, -2]
                ];
                foreach ($knightMoves as $move) {
                    list($mq, $mr) = $move;
                    if ($dq == $mq && $dr == $mr) {
                        return true;
                    }
                }
                return false;
                
            case 'queen':
                return ($this->isOrthogonalMove($dq, $dr) || $this->isDiagonalMove($dq, $dr)) && 
                       $this->isPathClear($fromQ, $fromR, $toQ, $toR);
                
            case 'king':
                // King can move like rook (6 directions) + bishop (6 directions) = 12 total
                
                // Check orthogonal moves: exactly 6 moves at distance 1
                if ($this->isOrthogonalMove($dq, $dr)) {
                    $ds = -$dq - $dr;
                    return max(abs($dq), abs($dr), abs($ds)) == 1;
                }
                
                // Check diagonal moves: exactly 6 specific diagonal moves
                if ($this->isDiagonalMove($dq, $dr)) {
                    $validDiagonals = [
                        [1, 1], [-1, -1],      // dq == dr condition
                        [-2, 1], [2, -1],     // dr == ds condition  
                        [1, -2], [-1, 2]      // dq == ds condition
                    ];
                    
                    foreach ($validDiagonals as $move) {
                        if ($dq == $move[0] && $dr == $move[1]) {
                            return true;
                        }
                    }
                }
                
                return false;
        }
        
        return false;
    }
    
    private function canPawnMove($piece, $fromQ, $fromR, $toQ, $toR) {
        $dq = $toQ - $fromQ;
        $dr = $toR - $fromR;

        // --- 2 players ---
        if ($this->playerCount == 2) {
            if ($piece->player == 0) { // Red (west edge) forward E
                if ($dq == 1 && $dr == 0) {
                    return !$this->getPiece($toQ, $toR);
                }
                if (($dq == 1 && $dr == 1) || ($dq == 2 && $dr == -1)) {
                    $t = $this->getPiece($toQ, $toR);
                    return $t && $t->player !== $piece->player;
                }
            } else { // Blue (east edge) forward W
                if ($dq == -1 && $dr == 0) {
                    return !$this->getPiece($toQ, $toR);
                }
                if (($dq == -1 && $dr == -1) || ($dq == -2 && $dr == 1)) {
                    $t = $this->getPiece($toQ, $toR);
                    return $t && $t->player !== $piece->player;
                }
            }
            return false;
        }

        // --- 3 players ---
        if ($this->playerCount == 3) {
            switch ($piece->player) {
                case 0: // Red (west) forward E
                    if ($dq == 1 && $dr == 0) return !$this->getPiece($toQ, $toR);
                    if (($dq == 1 && $dr == 1) || ($dq == 2 && $dr == -1)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;

                case 1: // Blue (NE) forward SW
                    if ($dq == -1 && $dr == 1) return !$this->getPiece($toQ, $toR);
                    if (($dq == -2 && $dr == 1) || ($dq == -1 && $dr == 2)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;

                case 2: // Green (SE) forward NW
                    if ($dq == 0 && $dr == -1) return !$this->getPiece($toQ, $toR);
                    if (($dq == -1 && $dr == -1) || ($dq == 1 && $dr == -2)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;
            }
        }

        // --- 4 players ---
        if ($this->playerCount == 4) {
            switch ($piece->player) {
                case 0: // Red (west) forward E
                    if ($dq == 1 && $dr == 0) return !$this->getPiece($toQ, $toR);
                    if (($dq == 1 && $dr == 1) || ($dq == 2 && $dr == -1)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;

                case 1: // Blue (NE) forward SW
                    if ($dq == -1 && $dr == 1) return !$this->getPiece($toQ, $toR);
                    if (($dq == -1 && $dr == 2) || ($dq == -2 && $dr == 1)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;

                case 2: // Green (east) forward W
                    if ($dq == -1 && $dr == 0) return !$this->getPiece($toQ, $toR);
                    if (($dq == -1 && $dr == -1) || ($dq == -2 && $dr == 1)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;

                case 3: // Yellow (SW) forward NE
                    if ($dq == 1 && $dr == -1) return !$this->getPiece($toQ, $toR);
                    if (($dq == 2 && $dr == -1) || ($dq == 1 && $dr == -2)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;
            }
        }

        // --- 5 players ---
        if ($this->playerCount == 5) {
            switch ($piece->player) {
                case 0: // Red (west) forward E
                    if ($dq == 1 && $dr == 0) return !$this->getPiece($toQ, $toR);
                    if (($dq == 1 && $dr == 1) || ($dq == 2 && $dr == -1)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;

                case 1: // Purple (NW) forward SE
                    if ($dq == 0 && $dr == 1) return !$this->getPiece($toQ, $toR);
                    if (($dq == -1 && $dr == 2) || ($dq == 1 && $dr == 1)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;

                case 2: // Blue (NE) forward SW
                    if ($dq == -1 && $dr == 1) return !$this->getPiece($toQ, $toR);
                    if (($dq == -1 && $dr == 2) || ($dq == -2 && $dr == 1)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;

                case 3: // Green (SE) forward NW
                    if ($dq == 0 && $dr == -1) return !$this->getPiece($toQ, $toR);
                    if (($dq == -1 && $dr == -1) || ($dq == 1 && $dr == -2)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;

                case 4: // Yellow (SW) forward NE
                    if ($dq == 1 && $dr == -1) return !$this->getPiece($toQ, $toR);
                    if (($dq == 2 && $dr == -1) || ($dq == 1 && $dr == -2)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;
            }
        }

        // --- 6 players ---
        if ($this->playerCount == 6) {
            switch ($piece->player) {
                case 0: // Red (west) forward E
                    if ($dq == 1 && $dr == 0) return !$this->getPiece($toQ, $toR);
                    if (($dq == 1 && $dr == 1) || ($dq == 2 && $dr == -1)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;

                case 1: // Blue (NW) forward SE
                    if ($dq == 0 && $dr == 1) return !$this->getPiece($toQ, $toR);
                    if (($dq == -1 && $dr == 2) || ($dq == 1 && $dr == 1)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;

                case 2: // Green (NE) forward SW
                    if ($dq == -1 && $dr == 1) return !$this->getPiece($toQ, $toR);
                    if (($dq == -1 && $dr == 2) || ($dq == -2 && $dr == 1)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;

                case 3: // Yellow (east) forward W
                    if ($dq == -1 && $dr == 0) return !$this->getPiece($toQ, $toR);
                    if (($dq == -1 && $dr == -1) || ($dq == -2 && $dr == 1)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;

                case 4: // Purple (SE) forward NW
                    if ($dq == 0 && $dr == -1) return !$this->getPiece($toQ, $toR);
                    if (($dq == -1 && $dr == -1) || ($dq == 1 && $dr == -2)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;

                case 5: // Bronze (SW) forward NE
                    if ($dq == 1 && $dr == -1) return !$this->getPiece($toQ, $toR);
                    if (($dq == 2 && $dr == -1) || ($dq == 1 && $dr == -2)) {
                        $t = $this->getPiece($toQ, $toR); return $t && $t->player !== $piece->player;
                    }
                    return false;
            }
        }

        return false;
    }

    // Make a move with error responses (API-style)
    public function makeMove($fromQ, $fromR, $toQ, $toR) {
        // Validate that there's a piece to move
        $piece = $this->getPiece($fromQ, $fromR);
        if (!$piece) {
            return [
                'success' => false, 
                'message' => 'No piece at selected position',
                'errorType' => 'no-piece'
            ];
        }
        
        // Check if it's the right player's piece
        if ($piece->player !== $this->currentPlayer) {
            return [
                'success' => false,
                'message' => 'You can only move your own pieces',
                'errorType' => 'wrong-player'
            ];
        }
        
        // Check if the move would leave king in check
        if ($this->gameState['moveCount'] >= 2 && $this->wouldLeaveKingInCheck($fromQ, $fromR, $toQ, $toR, $this->currentPlayer)) {
            return [
                'success' => false,
                'message' => 'That move would expose your king to check',
                'errorType' => 'exposes-king'
            ];
        }
        
        // Try to make the move
        if ($this->movePiece($fromQ, $fromR, $toQ, $toR)) {
            return [
                'success' => true,
                'message' => 'Move completed successfully'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Invalid move',
                'errorType' => 'invalid-move'
            ];
        }
    }

    // Check detection
    public function wouldLeaveKingInCheck($fromQ, $fromR, $toQ, $toR, $player) {
        $piece = $this->board[$fromQ][$fromR];
        $capturedPiece = $this->board[$toQ][$toR];
        
        $this->board[$toQ][$toR] = $piece;
        $this->board[$fromQ][$fromR] = null;
        
        $inCheck = $this->isKingInCheck($player);
        
        $this->board[$fromQ][$fromR] = $piece;
        $this->board[$toQ][$toR] = $capturedPiece;
        
        return $inCheck;
    }
    
    private function findKing($player) {
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize); 
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                
                $piece = $this->getPiece($q, $r);
                if ($piece && $piece->type === 'king' && $piece->player === $player) {
                    return ['q' => $q, 'r' => $r];
                }
            }
        }
        return null;
    }
    
    public function isKingInCheck($player) {
        $kingPos = $this->findKing($player);
        if (!$kingPos) return false;
        
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize); 
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                
                $piece = $this->getPiece($q, $r);
                if ($piece && $piece->player !== $player) {
                    if ($this->canPieceMoveTo($piece, $q, $r, $kingPos['q'], $kingPos['r'])) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    private function isValidMoveForPlayer($fromQ, $fromR, $toQ, $toR, $player) {
        $piece = $this->getPiece($fromQ, $fromR);
        
        if (!$piece || $piece->player !== $player) return false;
        if (!$this->isValidHex($toQ, $toR)) return false;
        if ($fromQ === $toQ && $fromR === $toR) return false;
        
        $targetPiece = $this->getPiece($toQ, $toR);
        if ($targetPiece && $targetPiece->player === $player) return false;
        
        if (!$this->canPieceMoveTo($piece, $fromQ, $fromR, $toQ, $toR)) return false;
        
        if ($this->gameState['moveCount'] >= 2) {
            if ($this->wouldLeaveKingInCheck($fromQ, $fromR, $toQ, $toR, $player)) {
                return false;
            }
        }
        
        return true;
    }
    
    public function hasLegalMoves($player) {
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize); 
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                
                $piece = $this->getPiece($q, $r);
                if ($piece && $piece->player === $player) {
                    $validMoves = $this->getValidMovesForPiece($q, $r);
                    if (count($validMoves) > 0) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    private function getValidMovesForPiece($fromQ, $fromR) {
        $piece = $this->getPiece($fromQ, $fromR);
        if (!$piece) return [];
        
        $validMoves = [];
        
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize); 
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                
                if ($q == $fromQ && $r == $fromR) continue;
                if (!$this->isValidHex($q, $r)) continue;
                
                if ($this->isValidMoveForPlayer($fromQ, $fromR, $q, $r, $piece->player)) {
                    $validMoves[] = ['q' => $q, 'r' => $r];
                }
            }
        }
        
        return $validMoves;
    }
    
    public function isCheckmate($player) {
        return $this->isKingInCheck($player) && !$this->hasLegalMoves($player);
    }
    
    public function isStalemate($player) {
        return !$this->isKingInCheck($player) && !$this->hasLegalMoves($player);
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
    
    public function getCellColor($q, $r) {
        $colorIndex = (($q - $r) % 3 + 3) % 3;
        $colors = ['pastel-red', 'pastel-green', 'pastel-blue'];
        return $colors[$colorIndex];
    }
    
    // Demo functionality (preserved for compatibility)
    public function getDemoValidMoves($fromQ, $fromR) {
        $piece = $this->getPiece($fromQ, $fromR);
        
        if (!$piece) {
            return [];
        }
        
        $validMoves = [];
        
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize); 
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                
                if ($q == $fromQ && $r == $fromR) continue;
                if (!$this->isValidHex($q, $r)) continue;
                
                // Check basic movement rules without turn/check restrictions
                if ($this->isDemoValidMove($fromQ, $fromR, $q, $r)) {
                    $validMoves[] = ['q' => $q, 'r' => $r];
                }
            }
        }
        
        return $validMoves;
    }
    
    // Demo move validation (relaxed rules)
    private function isDemoValidMove($fromQ, $fromR, $toQ, $toR) {
        $piece = $this->getPiece($fromQ, $fromR);
        
        if (!$piece) return false;
        if (!$this->isValidHex($toQ, $toR)) return false;
        if ($fromQ === $toQ && $fromR === $toR) return false;
        
        // Check if piece can move to destination
        return $this->canPieceMoveTo($piece, $fromQ, $fromR, $toQ, $toR);
    }
    
    // Clear board for demo
    public function clearBoard() {
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize); 
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                $this->board[$q][$r] = null;
            }
        }
    }
    
    // Place piece for demo
    public function placeDemoPiece($q, $r, $piece) {
        if ($this->isValidHex($q, $r)) {
            $this->board[$q][$r] = $piece;
        }
    }

    // === MOVE ENUMERATORS (full) ===
    public function getAllValidMoves() {
        $allMoves = [];
        
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize); 
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                
                $piece = $this->getPiece($q, $r);
                if ($piece && $piece->player === $this->currentPlayer) {
                    $validMoves = $this->getValidMoves($q, $r);
                    
                    foreach ($validMoves as $move) {
                        $allMoves[] = [
                            'fromQ' => $q,
                            'fromR' => $r,
                            'toQ' => $move['q'],
                            'toR' => $move['r']
                        ];
                    }
                }
            }
        }
        
        return $allMoves;
    }

    /**
     * Get all valid moves for a specific player (used by AI for evaluation)
     */
    public function getAllValidMovesForPlayer($playerSlot) {
        $allMoves = [];
        
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize); 
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                
                $piece = $this->getPiece($q, $r);
                if ($piece && $piece->player === $playerSlot) {
                    // Temporarily change current player to get moves
                    $originalPlayer = $this->currentPlayer;
                    $this->currentPlayer = $playerSlot;
                    
                    $validMoves = $this->getValidMoves($q, $r);
                    
                    // Restore original player
                    $this->currentPlayer = $originalPlayer;
                    
                    foreach ($validMoves as $move) {
                        $allMoves[] = [
                            'fromQ' => $q,
                            'fromR' => $r,
                            'toQ' => $move['q'],
                            'toR' => $move['r']
                        ];
                    }
                }
            }
        }
        
        return $allMoves;
    }
}
