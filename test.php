<?php
session_start();

class Piece {
    public $type;
    public $player;
    
    public function __construct($type, $player) {
        $this->type = $type;
        $this->player = $player;
    }
    
    public function getIcon() {
        $icons = [
            'king' => '♚', 'queen' => '♛', 'rook' => '♜',
            'bishop' => '♝', 'knight' => '♞', 'pawn' => '♟'
        ];
        
        return $icons[$this->type] ?? '';
    }
    
    public function getColorClass($playerCount) {
        if ($playerCount == 2) {
            $colors = ['red-piece', 'blue-piece'];
        } else {
            $colors = ['red-piece', 'blue-piece', 'green-piece'];
        }
        
        return $colors[$this->player] ?? '';
    }
}

class HexChess {
    private $board;
    private $currentPlayer;
    private $gameId;
    private $players;
    private $playerCount;
    private $moveCount;
    
    // Hexagonal board using axial coordinates (q, r)
    const BOARD_SIZE = 8;
    
    public function __construct($gameId = null, $playerCount = 2) {
        $this->gameId = $gameId ?: uniqid();
        $this->playerCount = $playerCount;
        $this->currentPlayer = 0;
        $this->moveCount = 0;
        
        if ($playerCount == 2) {
            $this->players = ['red', 'blue'];
        } else {
            $this->players = ['red', 'blue', 'green'];
        }
        
        $this->initBoard();
    }
    
    private function initBoard() {
        $this->board = [];
        
        // Initialize empty hexagonal board
        for ($q = -self::BOARD_SIZE; $q <= self::BOARD_SIZE; $q++) {
            for ($r = max(-self::BOARD_SIZE, -$q - self::BOARD_SIZE); 
                 $r <= min(self::BOARD_SIZE, -$q + self::BOARD_SIZE); $r++) {
                $this->board[$q][$r] = null;
            }
        }
        
        $this->setupPieces();
    }
    
    private function setupPieces() {
        if ($this->playerCount == 2) {
            $this->setupTwoPlayerPieces();
        } else {
            $this->setupThreePlayerPieces();
        }
    }
    
    private function setupTwoPlayerPieces() {
        // Player 1 (Red) - bottom-left corner
        $this->placePiece(-8, 0, new Piece('king', 0));
        $this->placePiece(-7, -1, new Piece('rook', 0));
        $this->placePiece(-6, -2, new Piece('knight', 0));
        $this->placePiece(-5, -3, new Piece('pawn', 0));
        $this->placePiece(-8, 1, new Piece('rook', 0));
        $this->placePiece(-7, 0, new Piece('queen', 0));
        $this->placePiece(-6, -1, new Piece('bishop', 0));
        $this->placePiece(-5, -2, new Piece('pawn', 0));
        $this->placePiece(-8, 2, new Piece('bishop', 0));
        $this->placePiece(-7, 1, new Piece('knight', 0));
        $this->placePiece(-6, 0, new Piece('bishop', 0));
        $this->placePiece(-5, -1, new Piece('pawn', 0));
        $this->placePiece(-8, 3, new Piece('pawn', 0));
        $this->placePiece(-7, 2, new Piece('pawn', 0));
        $this->placePiece(-6, 1, new Piece('pawn', 0));
        $this->placePiece(-5, 0, new Piece('pawn', 0));
        
        // Player 2 (Blue) - top-right corner
        $this->placePiece(8, 0, new Piece('king', 1));
        $this->placePiece(7, 1, new Piece('rook', 1));
        $this->placePiece(6, 2, new Piece('knight', 1));
        $this->placePiece(5, 3, new Piece('pawn', 1));
        $this->placePiece(8, -1, new Piece('rook', 1));
        $this->placePiece(7, 0, new Piece('queen', 1));
        $this->placePiece(6, 1, new Piece('bishop', 1));
        $this->placePiece(5, 2, new Piece('pawn', 1));
        $this->placePiece(8, -2, new Piece('bishop', 1));
        $this->placePiece(7, -1, new Piece('knight', 1));
        $this->placePiece(6, 0, new Piece('bishop', 1));
        $this->placePiece(5, 1, new Piece('pawn', 1));
        $this->placePiece(8, -3, new Piece('pawn', 1));
        $this->placePiece(7, -2, new Piece('pawn', 1));
        $this->placePiece(6, -1, new Piece('pawn', 1));
        $this->placePiece(5, 0, new Piece('pawn', 1));
    }
    
    private function setupThreePlayerPieces() {
        // Player 1 (Red) - bottom-left corner
        $this->placePiece(-8, 0, new Piece('king', 0));
        $this->placePiece(-7, -1, new Piece('rook', 0));
        $this->placePiece(-6, -2, new Piece('knight', 0));
        $this->placePiece(-5, -3, new Piece('pawn', 0));
        $this->placePiece(-8, 1, new Piece('rook', 0));
        $this->placePiece(-7, 0, new Piece('queen', 0));
        $this->placePiece(-6, -1, new Piece('bishop', 0));
        $this->placePiece(-5, -2, new Piece('pawn', 0));
        $this->placePiece(-8, 2, new Piece('bishop', 0));
        $this->placePiece(-7, 1, new Piece('knight', 0));
        $this->placePiece(-6, 0, new Piece('bishop', 0));
        $this->placePiece(-5, -1, new Piece('pawn', 0));
        $this->placePiece(-8, 3, new Piece('pawn', 0));
        $this->placePiece(-7, 2, new Piece('pawn', 0));
        $this->placePiece(-6, 1, new Piece('pawn', 0));
        $this->placePiece(-5, 0, new Piece('pawn', 0));
        
        // Player 2 (Blue) - right corner
        $this->placePiece(8, -8, new Piece('king', 1));
        $this->placePiece(7, -7, new Piece('queen', 1));
        $this->placePiece(7, -8, new Piece('rook', 1));
        $this->placePiece(8, -7, new Piece('rook', 1));
        $this->placePiece(6, -6, new Piece('bishop', 1));
        $this->placePiece(6, -7, new Piece('knight', 1));
        $this->placePiece(6, -8, new Piece('bishop', 1));
        $this->placePiece(7, -6, new Piece('bishop', 1));
        $this->placePiece(8, -6, new Piece('knight', 1));
        $this->placePiece(5, -6, new Piece('pawn', 1));
        $this->placePiece(5, -7, new Piece('pawn', 1));
        $this->placePiece(5, -8, new Piece('pawn', 1));
        $this->placePiece(6, -5, new Piece('pawn', 1));
        $this->placePiece(7, -5, new Piece('pawn', 1));
        $this->placePiece(8, -5, new Piece('pawn', 1));
        $this->placePiece(5, -5, new Piece('pawn', 1));
        
        // Player 3 (Green) - top corner
        $this->placePiece(-3, 8, new Piece('pawn', 2));
        $this->placePiece(-2, 7, new Piece('pawn', 2));
        $this->placePiece(-1, 6, new Piece('pawn', 2));
        $this->placePiece(0, 5, new Piece('pawn', 2));
        $this->placePiece(-2, 8, new Piece('bishop', 2));
        $this->placePiece(-1, 7, new Piece('knight', 2));
        $this->placePiece(0, 6, new Piece('bishop', 2));
        $this->placePiece(1, 5, new Piece('pawn', 2));
        $this->placePiece(-1, 8, new Piece('rook', 2));
        $this->placePiece(0, 7, new Piece('queen', 2));
        $this->placePiece(1, 6, new Piece('bishop', 2));
        $this->placePiece(2, 5, new Piece('pawn', 2));
        $this->placePiece(0, 8, new Piece('king', 2));
        $this->placePiece(1, 7, new Piece('rook', 2));
        $this->placePiece(2, 6, new Piece('knight', 2));
        $this->placePiece(3, 5, new Piece('pawn', 2));
    }
    
    private function placePiece($q, $r, $piece) {
        if ($this->isValidHex($q, $r)) {
            $this->board[$q][$r] = $piece;
        }
    }
    
    private function isValidHex($q, $r) {
        return abs($q) <= self::BOARD_SIZE && 
               abs($r) <= self::BOARD_SIZE && 
               abs($q + $r) <= self::BOARD_SIZE;
    }
    
    public function getPiece($q, $r) {
        return $this->isValidHex($q, $r) ? ($this->board[$q][$r] ?? null) : null;
    }
    
    // CRITICAL: Fixed path checking for hexagonal coordinates
    private function isPathClear($fromQ, $fromR, $toQ, $toR) {
        $dq = $toQ - $fromQ;
        $dr = $toR - $fromR;
        
        // Calculate the greatest common divisor to find step size
        $gcd = $this->gcd(abs($dq), abs($dr));
        if ($gcd == 0) return true; // Same position
        
        // Calculate unit steps
        $stepQ = $dq / $gcd;
        $stepR = $dr / $gcd;
        
        // Check each intermediate position
        for ($step = 1; $step < $gcd; $step++) {
            $checkQ = $fromQ + $stepQ * $step;
            $checkR = $fromR + $stepR * $step;
            
            if (!$this->isValidHex($checkQ, $checkR)) {
                return false;
            }
            
            if ($this->getPiece($checkQ, $checkR) !== null) {
                return false; // Path blocked
            }
        }
        
        return true;
    }
    
    // Helper function to calculate greatest common divisor
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
    
    // Check if move is orthogonal (rook-like) in hexagonal grid
    private function isOrthogonalMove($dq, $dr) {
        $ds = -$dq - $dr;
        
        // Three orthogonal directions in hex grid:
        // 1. Along q-axis: dr = 0, ds = -dq
        // 2. Along r-axis: dq = 0, ds = -dr  
        // 3. Along s-axis: dq = -dr, ds = 0
        return ($dr == 0) || ($dq == 0) || ($dq == -$dr);
    }
    
    // Check if move is diagonal (bishop-like) in hexagonal grid
    private function isDiagonalMove($dq, $dr) {
        $ds = -$dq - $dr;
        
        // Three diagonal directions in hex grid:
        // 1. dq = dr (both change by same amount)
        // 2. dr = ds (r and s change by same amount)
        // 3. dq = ds (q and s change by same amount)
        return ($dq == $dr) || ($dr == $ds) || ($dq == $ds);
    }
    
    public function movePiece($fromQ, $fromR, $toQ, $toR) {
        if (!$this->isValidMove($fromQ, $fromR, $toQ, $toR)) {
            return false;
        }
        
        $piece = $this->board[$fromQ][$fromR];
        $capturedPiece = $this->board[$toQ][$toR];
        
        // Make the move
        $this->board[$toQ][$toR] = $piece;
        $this->board[$fromQ][$fromR] = null;
        
        // Increment move counter
        $this->moveCount++;
        
        // Advance to next player
        $this->currentPlayer = ($this->currentPlayer + 1) % $this->playerCount;
        
        return true;
    }
    
    private function isValidMove($fromQ, $fromR, $toQ, $toR) {
        $piece = $this->getPiece($fromQ, $fromR);
        
        // Must have a piece
        if (!$piece) {
            return false;
        }
        
        // Must be current player's piece
        if ($piece->player !== $this->currentPlayer) {
            return false;
        }
        
        // Destination must be valid hex
        if (!$this->isValidHex($toQ, $toR)) {
            return false;
        }
        
        // Can't move to same position
        if ($fromQ === $toQ && $fromR === $toR) {
            return false;
        }
        
        // Can't capture own piece
        $targetPiece = $this->getPiece($toQ, $toR);
        if ($targetPiece && $targetPiece->player === $this->currentPlayer) {
            return false;
        }
        
        // Check if piece can move to destination
        if (!$this->canPieceMoveTo($piece, $fromQ, $fromR, $toQ, $toR)) {
            return false;
        }
        
        // Don't allow moves that leave own king in check (except for early game)
        if ($this->moveCount >= 4) {
            if ($this->wouldLeaveKingInCheck($fromQ, $fromR, $toQ, $toR, $this->currentPlayer)) {
                return false;
            }
        }
        
        return true;
    }
    
    // Check if a move would leave the player's own king in check
    public function wouldLeaveKingInCheck($fromQ, $fromR, $toQ, $toR, $player) {
        // Simulate the move
        $piece = $this->board[$fromQ][$fromR];
        $capturedPiece = $this->board[$toQ][$toR];
        
        $this->board[$toQ][$toR] = $piece;
        $this->board[$fromQ][$fromR] = null;
        
        // Check if king is in check after this move
        $inCheck = $this->isKingInCheck($player);
        
        // Undo the move
        $this->board[$fromQ][$fromR] = $piece;
        $this->board[$toQ][$toR] = $capturedPiece;
        
        return $inCheck;
    }
    
    // Find the king position for a player
    private function findKing($player) {
        for ($q = -self::BOARD_SIZE; $q <= self::BOARD_SIZE; $q++) {
            for ($r = max(-self::BOARD_SIZE, -$q - self::BOARD_SIZE); 
                 $r <= min(self::BOARD_SIZE, -$q + self::BOARD_SIZE); $r++) {
                
                $piece = $this->getPiece($q, $r);
                if ($piece && $piece->type === 'king' && $piece->player === $player) {
                    return ['q' => $q, 'r' => $r];
                }
            }
        }
        return null;
    }
    
    // Check if a player's king is currently in check
    public function isKingInCheck($player) {
        $kingPos = $this->findKing($player);
        if (!$kingPos) return false; // No king found
        
        // Check if any enemy piece can attack the king
        for ($q = -self::BOARD_SIZE; $q <= self::BOARD_SIZE; $q++) {
            for ($r = max(-self::BOARD_SIZE, -$q - self::BOARD_SIZE); 
                 $r <= min(self::BOARD_SIZE, -$q + self::BOARD_SIZE); $r++) {
                
                $piece = $this->getPiece($q, $r);
                if ($piece && $piece->player !== $player) {
                    // Check if this enemy piece can attack the king
                    if ($this->canPieceMoveTo($piece, $q, $r, $kingPos['q'], $kingPos['r'])) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    // Check if player has any legal moves
    public function hasLegalMoves($player) {
        for ($q = -self::BOARD_SIZE; $q <= self::BOARD_SIZE; $q++) {
            for ($r = max(-self::BOARD_SIZE, -$q - self::BOARD_SIZE); 
                 $r <= min(self::BOARD_SIZE, -$q + self::BOARD_SIZE); $r++) {
                
                $piece = $this->getPiece($q, $r);
                if ($piece && $piece->player === $player) {
                    // Check all possible moves for this piece
                    $validMoves = $this->getValidMovesForPiece($q, $r);
                    if (count($validMoves) > 0) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    // Check if player is in checkmate
    public function isCheckmate($player) {
        return $this->isKingInCheck($player) && !$this->hasLegalMoves($player);
    }
    
    // Check if player is in stalemate
    public function isStalemate($player) {
        return !$this->isKingInCheck($player) && !$this->hasLegalMoves($player);
    }
    
    // Get valid moves for a specific piece (used internally)
    private function getValidMovesForPiece($fromQ, $fromR) {
        $piece = $this->getPiece($fromQ, $fromR);
        if (!$piece) return [];
        
        $validMoves = [];
        
        // Check all possible destinations on the board
        for ($q = -self::BOARD_SIZE; $q <= self::BOARD_SIZE; $q++) {
            for ($r = max(-self::BOARD_SIZE, -$q - self::BOARD_SIZE); 
                 $r <= min(self::BOARD_SIZE, -$q + self::BOARD_SIZE); $r++) {
                
                // Skip same position
                if ($q == $fromQ && $r == $fromR) continue;
                
                // Skip invalid hexes
                if (!$this->isValidHex($q, $r)) continue;
                
                // Check if this is a valid move
                if ($this->isValidMoveForPlayer($fromQ, $fromR, $q, $r, $piece->player)) {
                    $validMoves[] = ['q' => $q, 'r' => $r];
                }
            }
        }
        
        return $validMoves;
    }
    
    // Validate move for specific player (used internally)
    private function isValidMoveForPlayer($fromQ, $fromR, $toQ, $toR, $player) {
        $piece = $this->getPiece($fromQ, $fromR);
        
        // Must have a piece
        if (!$piece || $piece->player !== $player) {
            return false;
        }
        
        // Destination must be valid hex
        if (!$this->isValidHex($toQ, $toR)) {
            return false;
        }
        
        // Can't move to same position
        if ($fromQ === $toQ && $fromR === $toR) {
            return false;
        }
        
        // Can't capture own piece
        $targetPiece = $this->getPiece($toQ, $toR);
        if ($targetPiece && $targetPiece->player === $player) {
            return false;
        }
        
        // Check if piece can move to destination
        if (!$this->canPieceMoveTo($piece, $fromQ, $fromR, $toQ, $toR)) {
            return false;
        }
        
        // Don't allow moves that leave own king in check (except early game)
        if ($this->moveCount >= 4) {
            if ($this->wouldLeaveKingInCheck($fromQ, $fromR, $toQ, $toR, $player)) {
                return false;
            }
        }
        
        return true;
    }
    
    private function canPieceMoveTo($piece, $fromQ, $fromR, $toQ, $toR) {
        $dq = $toQ - $fromQ;
        $dr = $toR - $fromR;
        $ds = -$dq - $dr;
        
        switch ($piece->type) {
            case 'pawn':
                return $this->canPawnMove($piece, $fromQ, $fromR, $toQ, $toR);
                
            case 'rook':
                // Rook moves along three main hex axes
                return $this->isOrthogonalMove($dq, $dr) && $this->isPathClear($fromQ, $fromR, $toQ, $toR);
                
            case 'bishop':
                // Bishop moves along three diagonal directions
                return $this->isDiagonalMove($dq, $dr) && $this->isPathClear($fromQ, $fromR, $toQ, $toR);
                
            case 'knight':
                // Knight jumps - no path checking needed
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
                // Queen combines rook and bishop movement
                return ($this->isOrthogonalMove($dq, $dr) || $this->isDiagonalMove($dq, $dr)) && 
                       $this->isPathClear($fromQ, $fromR, $toQ, $toR);
                
            case 'king':
                // King moves one step in any direction
                return (abs($dq) <= 1 && abs($dr) <= 1 && abs($ds) <= 1);
        }
        
        return false;
    }
    
    private function canPawnMove($piece, $fromQ, $fromR, $toQ, $toR) {
        $dq = $toQ - $fromQ;
        $dr = $toR - $fromR;
        
        if ($this->playerCount == 2) {
            if ($piece->player == 0) { // Red player
                // Forward move (toward +q direction)
                if ($dq == 1 && $dr == 0) {
                    return !$this->getPiece($toQ, $toR); // Empty square
                }
                // Diagonal captures
                if (($dq == 1 && $dr == 1) || ($dq == 2 && $dr == -1)) {
                    $targetPiece = $this->getPiece($toQ, $toR);
                    return $targetPiece && $targetPiece->player !== $piece->player;
                }
            } else { // Blue player
                // Forward move (toward -q direction)
                if ($dq == -1 && $dr == 0) {
                    return !$this->getPiece($toQ, $toR); // Empty square
                }
                // Diagonal captures
                if (($dq == -1 && $dr == -1) || ($dq == -2 && $dr == 1)) {
                    $targetPiece = $this->getPiece($toQ, $toR);
                    return $targetPiece && $targetPiece->player !== $piece->player;
                }
            }
        } else {
            // 3-player pawn movement (simplified for now)
            if ($piece->player == 0) { // Red
                if ($dq == 1 && $dr == 0) {
                    return !$this->getPiece($toQ, $toR);
                }
                if (($dq == 1 && $dr == 1) || ($dq == 2 && $dr == -1)) {
                    $targetPiece = $this->getPiece($toQ, $toR);
                    return $targetPiece && $targetPiece->player !== $piece->player;
                }
            } else if ($piece->player == 1) { // Blue
                if ($dq == -1 && $dr == 1) {
                    return !$this->getPiece($toQ, $toR);
                }
                if (($dq == -1 && $dr == 0) || ($dq == -2 && $dr == 2)) {
                    $targetPiece = $this->getPiece($toQ, $toR);
                    return $targetPiece && $targetPiece->player !== $piece->player;
                }
            } else { // Green
                if ($dq == 0 && $dr == -1) {
                    return !$this->getPiece($toQ, $toR);
                }
                if (($dq == 1 && $dr == -1) || ($dq == -1 && $dr == -2)) {
                    $targetPiece = $this->getPiece($toQ, $toR);
                    return $targetPiece && $targetPiece->player !== $piece->player;
                }
            }
        }
        
        return false;
    }
    
    public function getValidMoves($fromQ, $fromR) {
        $piece = $this->getPiece($fromQ, $fromR);
        
        // Return empty array if no piece or not current player's piece
        if (!$piece || $piece->player !== $this->currentPlayer) {
            return [];
        }
        
        return $this->getValidMovesForPiece($fromQ, $fromR);
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
    
    public function getGameState() {
        $currentPlayerInCheck = $this->isKingInCheck($this->currentPlayer);
        $currentPlayerCheckmate = $this->isCheckmate($this->currentPlayer);
        $currentPlayerStalemate = $this->isStalemate($this->currentPlayer);
        
        $gameOver = $currentPlayerCheckmate || $currentPlayerStalemate;
        $winner = null;
        
        if ($currentPlayerCheckmate) {
            // Current player loses, so winner is next player
            $winner = ($this->currentPlayer + 1) % $this->playerCount;
        }
        
        // Find king positions for highlighting
        $kingPositions = [];
        for ($player = 0; $player < $this->playerCount; $player++) {
            $kingPos = $this->findKing($player);
            if ($kingPos && $this->isKingInCheck($player)) {
                $kingPositions[] = $kingPos;
            }
        }
        
        return [
            'gameId' => $this->gameId,
            'currentPlayer' => $this->currentPlayer,
            'playerCount' => $this->playerCount,
            'moveCount' => $this->moveCount,
            'board' => $this->board,
            'gameStatus' => [
                'gameOver' => $gameOver,
                'winner' => $winner,
                'checkmate' => $currentPlayerCheckmate,
                'stalemate' => $currentPlayerStalemate
            ],
            'isInCheck' => $currentPlayerInCheck,
            'kingsInCheck' => $kingPositions
        ];
    }
}

// Game controller
$playerCount = isset($_GET['players']) ? (int)$_GET['players'] : 2;
if ($playerCount < 2 || $playerCount > 3) $playerCount = 2;

$game = isset($_SESSION['hexchess']) ? unserialize($_SESSION['hexchess']) : new HexChess(null, $playerCount);

// Handle AJAX requests
if ($_POST['action'] ?? '') {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'move':
            $fromQ = (int)$_POST['fromQ'];
            $fromR = (int)$_POST['fromR'];
            $toQ = (int)$_POST['toQ'];
            $toR = (int)$_POST['toR'];
            
            if ($game->movePiece($fromQ, $fromR, $toQ, $toR)) {
                $gameState = $game->getGameState();
                echo json_encode([
                    'success' => true, 
                    'currentPlayer' => $game->getCurrentPlayer(),
                    'gameStatus' => $gameState['gameStatus'],
                    'isInCheck' => $gameState['isInCheck'],
                    'kingsInCheck' => $gameState['kingsInCheck']
                ]);
            } else {
                // Provide detailed error messages
                $piece = $game->getPiece($fromQ, $fromR);
                $errorType = 'invalid';
                $errorMessage = 'Invalid move';
                
                if (!$piece) {
                    $errorType = 'no-piece';
                    $errorMessage = 'No piece at selected position';
                } elseif ($piece->player !== $game->getCurrentPlayer()) {
                    $errorType = 'wrong-player';
                    $errorMessage = 'Not your piece';
                } elseif ($game->wouldLeaveKingInCheck($fromQ, $fromR, $toQ, $toR, $game->getCurrentPlayer())) {
                    $errorType = 'exposes-king';
                    $errorMessage = 'Move would expose your king to check';
                }
                
                echo json_encode([
                    'success' => false, 
                    'error' => $errorMessage,
                    'errorType' => $errorType
                ]);
            }
            break;
            
        case 'getValidMoves':
            $fromQ = (int)$_POST['fromQ'];
            $fromR = (int)$_POST['fromR'];
            $validMoves = $game->getValidMoves($fromQ, $fromR);
            echo json_encode(['success' => true, 'validMoves' => $validMoves]);
            break;
            
        case 'newgame':
            $newPlayerCount = isset($_POST['playerCount']) ? (int)$_POST['playerCount'] : $playerCount;
            if ($newPlayerCount < 2 || $newPlayerCount > 3) $newPlayerCount = 2;
            $game = new HexChess(null, $newPlayerCount);
            echo json_encode(['success' => true, 'playerCount' => $newPlayerCount]);
            break;
    }
    
    $_SESSION['hexchess'] = serialize($game);
    exit;
}

$_SESSION['hexchess'] = serialize($game);

function renderBoard($game) {
    $html = '<div class="hex-board">';
    
    for ($r = HexChess::BOARD_SIZE; $r >= -HexChess::BOARD_SIZE; $r--) {
        $html .= '<div class="hex-row">';
        
        for ($q = -HexChess::BOARD_SIZE; $q <= HexChess::BOARD_SIZE; $q++) {
            if (abs($q) <= HexChess::BOARD_SIZE && 
                abs($r) <= HexChess::BOARD_SIZE && 
                abs($q + $r) <= HexChess::BOARD_SIZE) {
                
                $piece = $game->getPiece($q, $r);
                $cellColor = $game->getCellColor($q, $r);
                $pieceIcon = '';
                
                if ($piece) {
                    $icon = $piece->getIcon();
                    $colorClass = $piece->getColorClass($game->getPlayerCount());
                    $pieceIcon = "<span class='piece $colorClass'>$icon</span>";
                }
                
                $html .= "<div class='hex-cell $cellColor' data-q='$q' data-r='$r' onclick='selectHex($q, $r)'>
                            <div class='hex-inner'>
                                <div class='hex-content'>$pieceIcon</div>
                            </div>
                          </div>";
            }
        }
        
        $html .= '</div>';
    }
    
    $html .= '</div>';
    return $html;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $game->getPlayerCount(); ?>-Player Hexagonal Chess</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            height: 100vh;
            display: flex;
            overflow: hidden;
        }
        
        .sidebar {
            width: 320px;
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(20px);
            border-right: 1px solid rgba(255, 255, 255, 0.2);
            padding: 20px;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }
        
        .sidebar h1 {
            font-size: 18px;
            margin-bottom: 25px;
            text-align: center;
            background: linear-gradient(45deg, #fff, #e0e0e0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .player-info {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
        }
        
        .player-info h3 {
            font-size: 14px;
            margin-bottom: 8px;
            opacity: 0.8;
        }
        
        .current-player {
            font-size: 18px;
            font-weight: bold;
            text-transform: capitalize;
        }
        
        .game-status {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
        }
        
        .controls {
            margin-bottom: 25px;
        }
        
        .btn {
            width: 100%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 10px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn:hover {
            transform: translateY(-1px);
            filter: brightness(1.1);
        }
        
        .game-mode-selector {
            display: flex;
            gap: 5px;
            margin-bottom: 10px;
        }
        
        .mode-btn {
            flex: 1;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 8px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .mode-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-1px);
        }
        
        .mode-btn.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-color: #667eea;
        }
        
        .instructions {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 15px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 13px;
            line-height: 1.4;
            flex-grow: 1;
        }
        
        /* Notification System */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            border-left: 4px solid #e74c3c;
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            max-width: 300px;
        }
        
        .notification.show {
            transform: translateX(0);
        }
        
        .notification.success {
            border-left-color: #2ecc71;
        }
        
        .notification.warning {
            border-left-color: #f39c12;
        }
        
        .notification.error {
            border-left-color: #e74c3c;
        }
        
        .notification-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .notification-message {
            font-size: 12px;
            opacity: 0.9;
        }
        
        .instructions h3 {
            font-size: 16px;
            margin-bottom: 15px;
            color: #fff;
        }
        
        .instructions ul {
            list-style: none;
        }
        
        .instructions li {
            margin-bottom: 8px;
            padding-left: 15px;
            position: relative;
        }
        
        .instructions li:before {
            content: '• ';
            position: absolute;
            left: 0;
            color: #667eea;
            font-weight: bold;
        }
        
        .main-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow: auto;
        }
        
        .hex-board {
            display: flex;
            flex-direction: column;
            align-items: center;
            transform: scale(0.8);
            margin: 50px auto 0 auto;
        }
        
        .hex-row {
            display: flex;
            margin: -6px 0;
            position: relative;
            justify-content: center;
        }
        
        .hex-row:nth-child(even) {
            margin-left: 2px;
        }
        
        .hex-cell {
            width: 52px;
            height: 60px;
            margin: 0 2px;
            position: relative;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        
        .hex-inner {
            width: 100%;
            height: 100%;
            position: relative;
            clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
        }
        
        .hex-cell.pastel-red .hex-inner {
            background: linear-gradient(135deg, #ffb3ba, #ff9999);
        }
        
        .hex-cell.pastel-blue .hex-inner {
            background: linear-gradient(135deg, #bae1ff, #87ceeb);
        }
        
        .hex-cell.pastel-green .hex-inner {
            background: linear-gradient(135deg, #baffc9, #90ee90);
        }
        
        .hex-cell:hover {
            transform: scale(1.05);
            z-index: 10;
        }
        
        .hex-cell.selected {
            transform: scale(1.1);
            z-index: 20;
        }
        
        .hex-cell.selected .hex-inner {
            border: 3px solid #2ecc71;
            background: rgba(46, 204, 113, 0.2) !important;
        }
        
        .hex-cell.king-in-check {
            animation: checkPulse 1.5s infinite;
            z-index: 25;
        }
        
        .hex-cell.king-in-check .hex-inner {
            border: 4px solid #e74c3c !important;
            background: rgba(231, 76, 60, 0.3) !important;
        }
        
        @keyframes checkPulse {
            0%, 100% { 
                transform: scale(1.1);
                opacity: 1;
            }
            50% { 
                transform: scale(1.25);
                opacity: 0.7;
            }
        }
        
        .hex-cell.valid-move {
            animation: pulseMove 1.2s infinite;
            z-index: 15;
        }
        
        .hex-cell.valid-move .hex-inner {
            border: 4px solid #2ecc71 !important;
            background: rgba(46, 204, 113, 0.4) !important;
        }
        
        @keyframes pulseMove {
            0%, 100% { 
                transform: scale(1.0);
                opacity: 1;
            }
            50% { 
                transform: scale(1.15);
                opacity: 0.8;
            }
        }
        
        .hex-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 5;
            pointer-events: none;
        }
        
        .piece {
            font-size: 52px;
            font-weight: 900;
            line-height: 1;
            text-shadow: 
                -1px -1px 0 #000,
                1px -1px 0 #000,
                -1px 1px 0 #000,
                1px 1px 0 #000;
            filter: none;
            transition: all 0.2s ease;
            user-select: none;
            opacity: 1;
        }
        
        .piece.red-piece {
            color: #dc143c;
        }
        
        .piece.blue-piece {
            color: #1e90ff;
        }
        
        .piece.green-piece {
            color: #228b22;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h1>🏰 Hexagonal Chess</h1>
        
        <div class="player-info">
            <h3>Current Turn</h3>
            <div class="current-player" id="currentPlayer">
                <?php echo ucfirst($game->getCurrentPlayer()); ?>
            </div>
        </div>
        
        <div class="game-status">
            <?php 
            $gameState = $game->getGameState();
            $isInCheck = $gameState['isInCheck'];
            $gameStatus = $gameState['gameStatus'];
            
            if ($gameStatus['gameOver']) {
                if ($gameStatus['checkmate']) {
                    $winner = $game->getPlayers()[$gameStatus['winner']];
                    echo "<h3>🏆 Checkmate!</h3>";
                    echo "<p>" . ucfirst($winner) . " wins!</p>";
                } elseif ($gameStatus['stalemate']) {
                    echo "<h3>🤝 Stalemate!</h3>";
                    echo "<p>Game is a draw!</p>";
                }
            } elseif ($isInCheck) {
                echo "<h3>⚠️ Check!</h3>";
                echo "<p>" . ucfirst($game->getCurrentPlayer()) . " king in danger!</p>";
            } else {
                echo "<h3>🎯 Game Active</h3>";
                echo "<p>Make your move!</p>";
            }
            ?>
        </div>
        
        <div class="controls">
            <div class="game-mode-selector">
                <button class="mode-btn <?php echo $game->getPlayerCount() == 2 ? 'active' : ''; ?>" onclick="setGameMode(2)">
                    2 Players
                </button>
                <button class="mode-btn <?php echo $game->getPlayerCount() == 3 ? 'active' : ''; ?>" onclick="setGameMode(3)">
                    3 Players
                </button>
            </div>
            <button class="btn" onclick="newGame()">
                🎮 New Game
            </button>
            <button class="btn" onclick="location.reload()">
                🔄 Refresh
            </button>
        </div>
        
        <div class="instructions">
            <h3>How to Play</h3>
            <ul>
                <li>Click piece, then click destination</li>
                <li>Goal: Checkmate opposing king(s)</li>
                <li>Pieces move in hexagonal patterns</li>
                <li>Path must be clear for sliding pieces</li>
                <?php if ($game->getPlayerCount() == 2): ?>
                <li>Turn order: Red → Blue</li>
                <?php else: ?>
                <li>Turn order: Red → Blue → Green</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    
    <div class="main-content">
        <?php echo renderBoard($game); ?>
    </div>

    <script>
        let selectedHex = null;
        let gameOver = false;
        
        function selectHex(q, r) {
            if (gameOver) {
                alert('Game is over! Start a new game to continue playing.');
                return;
            }
            
            const cell = document.querySelector(`[data-q="${q}"][data-r="${r}"]`);
            
            if (selectedHex && selectedHex.q === q && selectedHex.r === r) {
                // Deselecting same hex
                clearSelection();
                return;
            }
            
            if (selectedHex && cell.classList.contains('valid-move')) {
                // Making move to valid destination
                makeMove(selectedHex.q, selectedHex.r, q, r);
                return;
            }
            
            // Selecting new hex and getting valid moves
            clearSelection();
            selectedHex = {q: q, r: r};
            cell.classList.add('selected');
            getValidMoves(q, r);
        }
        
        function clearSelection() {
            document.querySelectorAll('.hex-cell').forEach(cell => {
                cell.classList.remove('selected', 'valid-move');
            });
            selectedHex = null;
        }
        
        function clearCheckHighlights() {
            document.querySelectorAll('.hex-cell').forEach(cell => {
                cell.classList.remove('king-in-check');
            });
        }
        
        function showNotification(title, message, type = 'error') {
            // Remove existing notifications
            const existing = document.querySelector('.notification');
            if (existing) existing.remove();
            
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <div class="notification-title">${title}</div>
                <div class="notification-message">${message}</div>
            `;
            
            document.body.appendChild(notification);
            
            // Show notification
            setTimeout(() => notification.classList.add('show'), 100);
            
            // Auto-hide after 3 seconds
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }
        
        function highlightKingBriefly(q, r) {
            const kingCell = document.querySelector(`[data-q="${q}"][data-r="${r}"]`);
            if (kingCell) {
                kingCell.classList.add('king-in-check');
                setTimeout(() => {
                    kingCell.classList.remove('king-in-check');
                }, 2000);
            }
        }
        
        function highlightKingsInCheck(kingsInCheck) {
            clearCheckHighlights();
            kingsInCheck.forEach(king => {
                const kingCell = document.querySelector(`[data-q="${king.q}"][data-r="${king.r}"]`);
                if (kingCell) {
                    kingCell.classList.add('king-in-check');
                }
            });
        }
        
        function getValidMoves(q, r) {
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=getValidMoves&fromQ=${q}&fromR=${r}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.validMoves && data.validMoves.length > 0) {
                    data.validMoves.forEach(move => {
                        const targetCell = document.querySelector(`[data-q="${move.q}"][data-r="${move.r}"]`);
                        if (targetCell) {
                            targetCell.classList.add('valid-move');
                        }
                    });
                } else {
                    // No valid moves found
                    clearSelection();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                clearSelection();
            });
        }
        
        function makeMove(fromQ, fromR, toQ, toR) {
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=move&fromQ=${fromQ}&fromR=${fromR}&toQ=${toQ}&toR=${toR}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Check for game end conditions
                    if (data.gameStatus && data.gameStatus.gameOver) {
                        gameOver = true;
                        if (data.gameStatus.checkmate) {
                            // Will be handled by page reload
                        } else if (data.gameStatus.stalemate) {
                            // Will be handled by page reload
                        }
                    }
                    
                    setTimeout(() => location.reload(), 150);
                } else {
                    // Handle different error types with appropriate notifications
                    if (data.errorType === 'exposes-king') {
                        showNotification('⚠️ King in Danger!', 'That move would expose your king to check', 'warning');
                        // TODO: Could highlight the king that would be exposed
                    } else if (data.errorType === 'wrong-player') {
                        showNotification('❌ Wrong Piece', 'You can only move your own pieces', 'error');
                    } else if (data.errorType === 'no-piece') {
                        showNotification('❌ No Piece', 'No piece at selected position', 'error');
                    } else {
                        showNotification('❌ Invalid Move', data.error || 'Move not allowed', 'error');
                    }
                }
                clearSelection();
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('❌ Connection Error', 'Unable to make move', 'error');
                clearSelection();
            });
        }
        
        function newGame() {
            const currentMode = document.querySelector('.mode-btn.active').textContent.includes('2') ? 2 : 3;
            
            if (confirm(`Start a new ${currentMode}-player game?`)) {
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=newgame&playerCount=${currentMode}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('🎮 New Game', 'Starting fresh game!', 'success');
                        setTimeout(() => location.reload(), 500);
                    } else {
                        showNotification('❌ Error', 'Failed to start new game', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('❌ Connection Error', 'Unable to start new game', 'error');
                });
            }
        }
        
        function setGameMode(playerCount) {
            if (confirm(`Switch to ${playerCount}-player mode? This will start a new game.`)) {
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=newgame&playerCount=${playerCount}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                })
                .catch(error => console.error('Error:', error));
            }
        }
        
        // Initialize check highlighting on page load
        document.addEventListener('DOMContentLoaded', function() {
            <?php 
            $gameState = $game->getGameState();
            if (!empty($gameState['kingsInCheck'])): 
            ?>
            const kingsInCheck = <?php echo json_encode($gameState['kingsInCheck']); ?>;
            highlightKingsInCheck(kingsInCheck);
            <?php endif; ?>
            
            <?php if ($gameState['gameStatus']['gameOver']): ?>
            gameOver = true;
            <?php endif; ?>
        });
    </script>
</body>
</html>