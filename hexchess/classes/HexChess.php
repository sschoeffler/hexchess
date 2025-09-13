<?php
// classes/HexChess.php - Refactored version extending BaseChess
// Preserves ALL existing functionality while gaining shared infrastructure
// Fixes: hex diagonal logic, path stepping (no rounding), and legal move generation.
// Adds: per-player auto-detected pawn forward/capture directions.

require_once 'BaseChess.php';
require_once 'Piece.php';

class HexChess extends BaseChess {
    use HexagonalCoordinates;

    // === HEX-SPECIFIC PROPERTIES ===
    private $board;
    private $boardSize;

    // Explicit visibility for fields referenced in (de)serialization
    protected $players = ['red', 'blue', 'green', 'yellow', 'purple', 'orange'];
    protected $activePlayers = [];
    protected $playerUsers = [];

    // Cache per-player pawn forward direction (index into orthogonalUnitDirs)
    private $pawnForwardIndex = [];

    public function __construct($gameId = null, $playerCount = 2, $boardSize = 8, $fogOfWar = false) {
        // Ensure board size is set before BaseChess uses it
        $this->boardSize = (int)$boardSize;

        // sensible defaults
        $pc = max(2, min(6, (int)$playerCount));
        $this->activePlayers = array_fill(0, $pc, true);
        $this->playerUsers   = array_fill(0, $pc, null);

        // Call parent constructor
        parent::__construct($gameId, $playerCount, $boardSize);

        // Enable features if requested
        if ($fogOfWar) {
            $this->enableFogOfWar();
        }
    }

    // === IMPLEMENT ABSTRACT METHODS FROM BASECHESS ===

    public function getBoardSize() {
        return $this->boardSize;
    }

    protected function initializeBoard() {
        $this->board = [];
        $this->pawnForwardIndex = []; // reset pawn forward cache

        // Initialize hexagonal board (axial coords)
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize);
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                if (!isset($this->board[$q])) {
                    $this->board[$q] = [];
                }
                $this->board[$q][$r] = null;
            }
        }

        // Setup pieces based on player count
        $this->setupPieces();
    }

    public function getBoard() {
        if ($this->isFogOfWarEnabled()) {
            return $this->getFogOfWarBoard($this->getCurrentPlayerSlot());
        }
        return $this->board;
    }

    public function getPiece($q, $r) {
        return $this->board[$q][$r] ?? null;
    }

    protected function executePieceMove($fromQ, $fromR, $toQ, $toR) {
        $piece = $this->board[$fromQ][$fromR];
        $this->board[$toQ][$toR] = $piece;
        $this->board[$fromQ][$fromR] = null;
    }

    protected function executePieceDrop($playerSlot, $pieceType, $q, $r) {
        $this->board[$q][$r] = new Piece($pieceType, $playerSlot);
    }

    /**
     * LEGAL move generation per piece. (Used by UI to show highlights.)
     * Avoids "all hexes" + filter, which can hide valid rays when path math is off.
     */
protected function generatePossibleMoves($fromQ, $fromR) {
    $piece = $this->getPiece($fromQ, $fromR);
    if (!$piece) return [];

    $moves = [];
    $isInCheck = $this->isKingInCheck($piece->player);

    switch ($piece->type) {
        case 'pawn':
            // Forward moves
            foreach ($this->getPawnForwardDirections($piece->player) as [$fq, $fr]) {
                $tq = $fromQ + $fq;
                $tr = $fromR + $fr;
                if ($this->isValidHex($tq, $tr) && !$this->getPiece($tq, $tr)) {
                    if ($this->isValidMove($fromQ, $fromR, $tq, $tr)) {
                        $moves[] = ['q' => $tq, 'r' => $tr];
                    }
                }
                // Double move from start
                $tq2 = $fromQ + 2*$fq;
                $tr2 = $fromR + 2*$fr;
                if ($this->isValidHex($tq2, $tr2) &&
                    $this->isPawnStartingPosition($piece->player, $fromQ, $fromR) &&
                    !$this->getPiece($tq, $tr) && !$this->getPiece($tq2, $tr2) &&
                    $this->isValidMove($fromQ, $fromR, $tq2, $tr2)) {
                    $moves[] = ['q' => $tq2, 'r' => $tr2];
                }

                // Captures
                foreach ($this->getPawnCaptureDirections($piece->player) as [$cq,$cr]) {
                    $ctq = $fromQ + $cq;
                    $ctr = $fromR + $cr;
                    if ($this->isValidHex($ctq, $ctr)) {
                        $target = $this->getPiece($ctq, $ctr);
                        if ($target && $target->player !== $piece->player) {
                            if ($this->isMoveValidIgnoringCheck($fromQ, $fromR, $ctq, $ctr) &&
                                !$this->wouldLeaveKingInCheck($fromQ, $fromR, $ctq, $ctr, $piece->player)) {
                                $moves[] = ['q' => $ctq, 'r' => $ctr];
                            }
                        }
                    }
                }
            }
            break;

        case 'rook':
            foreach ($this->orthogonalUnitDirs() as [$dq,$dr]) {
                $this->pushRayFixed($fromQ, $fromR, $dq, $dr, $moves, $piece->player);
            }
            break;

        case 'bishop':
            foreach ($this->diagonalUnitDirs() as [$dq,$dr]) {
                $this->pushRayFixed($fromQ, $fromR, $dq, $dr, $moves, $piece->player);
            }
            break;

        case 'queen':
            foreach (array_merge($this->orthogonalUnitDirs(), $this->diagonalUnitDirs()) as [$dq,$dr]) {
                $this->pushRayFixed($fromQ, $fromR, $dq, $dr, $moves, $piece->player);
            }
            break;

        case 'king':
            foreach (array_merge($this->orthogonalUnitDirs(), $this->diagonalUnitDirs()) as [$dq,$dr]) {
                $tq = $fromQ + $dq;
                $tr = $fromR + $dr;
                if ($this->isValidHex($tq, $tr)) {
                    if ($this->isMoveValidIgnoringCheck($fromQ, $fromR, $tq, $tr) &&
                        !$this->wouldLeaveKingInCheck($fromQ, $fromR, $tq, $tr, $piece->player)) {
                        $moves[] = ['q' => $tq, 'r' => $tr];
                    }
                }
            }
            break;

        case 'knight':
            foreach ($this->knightJumps() as [$dq,$dr]) {
                $tq = $fromQ + $dq;
                $tr = $fromR + $dr;
                if ($this->isValidHex($tq, $tr)) {
                    if ($this->isMoveValidIgnoringCheck($fromQ, $fromR, $tq, $tr) &&
                        !$this->wouldLeaveKingInCheck($fromQ, $fromR, $tq, $tr, $piece->player)) {
                        $moves[] = ['q' => $tq, 'r' => $tr];
                    }
                }
            }
            break;
    }

    return $moves;
}

private function pushRayFixed($fromQ, $fromR, $dq, $dr, array &$moves, $player) {
    $q = $fromQ + $dq;
    $r = $fromR + $dr;

    while ($this->isValidHex($q, $r)) {
        $target = $this->getPiece($q, $r);
        if ($target) {
            if ($target->player !== $player) {
                // Capture - always check if it resolves check
                if ($this->isMoveValidIgnoringCheck($fromQ, $fromR, $q, $r) &&
                    !$this->wouldLeaveKingInCheck($fromQ, $fromR, $q, $r, $player)) {
                    $moves[] = ['q' => $q, 'r' => $r];
                }
            }
            break; // blocked
        } else {
            // Empty square
            if ($this->isMoveValidIgnoringCheck($fromQ, $fromR, $q, $r) &&
                !$this->wouldLeaveKingInCheck($fromQ, $fromR, $q, $r, $player)) {
                $moves[] = ['q' => $q, 'r' => $r];
            }
        }
        $q += $dq;
        $r += $dr;
    }
}

private function isMoveValidIgnoringCheck($fromQ, $fromR, $toQ, $toR) {
    $piece = $this->getPiece($fromQ, $fromR);
    
    if (!$piece) return false;
    if ($piece->player !== $this->currentPlayer) return false;
    if (!$this->isValidHex($toQ, $toR)) return false;
    if ($fromQ === $toQ && $fromR === $toR) return false;
    
    $targetPiece = $this->getPiece($toQ, $toR);
    if ($targetPiece && $targetPiece->player === $this->currentPlayer) return false;
    
    return $this->canPieceMoveTo($piece, $fromQ, $fromR, $toQ, $toR);
}

    protected function getAllPiecesForPlayer($player) {
        $pieces = [];

        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize);
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {

                $piece = $this->getPiece($q, $r);
                if ($piece && $piece->player === $player) {
                    $pieces[] = ['q' => $q, 'r' => $r, 'piece' => $piece];
                }
            }
        }

        return $pieces;
    }

    protected function isValidDropPosition($playerSlot, $pieceType, $q, $r) {
        if (!$this->isValidHex($q, $r)) {
            return false;
        }

        // Position must be empty
        if ($this->getPiece($q, $r) !== null) {
            return false;
        }

        // Pawn drop restrictions
        if ($pieceType === 'pawn') {
            return $this->isValidPawnDropPosition($playerSlot, $q, $r);
        }

        return true;
    }

    // === MOVEMENT VALIDATION ===

    public function isValidMove($fromQ, $fromR, $toQ, $toR) {
        $piece = $this->getPiece($fromQ, $fromR);

        // Basic validation
        if (!$piece) return false;
        if ($piece->player !== $this->currentPlayer || !$this->activePlayers[$this->currentPlayer]) return false;
        if (!$this->isValidHex($toQ, $toR)) return false;
        if ($fromQ === $toQ && $fromR === $toR) return false;

        // Can't capture own pieces
        $targetPiece = $this->getPiece($toQ, $toR);
        if ($targetPiece && $targetPiece->player === $this->currentPlayer) return false;

        // Piece-specific movement validation
        if (!$this->canPieceMoveTo($piece, $fromQ, $fromR, $toQ, $toR)) return false;

        // King safety check (after first two moves)
        if ($this->moveCount >= 2) {
            if ($this->wouldLeaveKingInCheck($fromQ, $fromR, $toQ, $toR, $this->currentPlayer)) {
                return false;
            }
        }

        return true;
    }

    protected function canPieceMoveTo($piece, $fromQ, $fromR, $toQ, $toR) {
        $dq = $toQ - $fromQ;
        $dr = $toR - $fromR;

        switch ($piece->type) {
            case 'pawn':
                return $this->canPawnMove($piece, $fromQ, $fromR, $toQ, $toR);

            case 'rook':
                return $this->isOrthogonalMove($dq, $dr) && $this->isPathClear($fromQ, $fromR, $toQ, $toR);

            case 'bishop':
                return $this->isDiagonalMoveHex($dq, $dr) && $this->isPathClear($fromQ, $fromR, $toQ, $toR);

            case 'knight':
                return $this->isKnightMove($dq, $dr);

            case 'queen':
                return ($this->isOrthogonalMove($dq, $dr) || $this->isDiagonalMoveHex($dq, $dr)) &&
                       $this->isPathClear($fromQ, $fromR, $toQ, $toR);

            case 'king':
                return $this->isKingMove($dq, $dr);

            default:
                return false;
        }
    }

    // === DIRECTION LOGIC ===

    private function isOrthogonalMove($dq, $dr) {
        // axial orthogonals: q const, r const, or s const (s = -q - r)
        return ($dq == 0 && $dr != 0) || ($dr == 0 && $dq != 0) || (($dq + $dr) == 0 && $dq != 0);
    }

    /**
     * True hex "color-diagonal" test for bishops.
     * Using cube relation with s = -q - r:
     *   diagonal if (dq==dr) OR (dr==ds) OR (dq==ds), with ds = -dq - dr; and not zero.
     */
    private function isDiagonalMoveHex($dq, $dr) {
        if ($dq == 0 && $dr == 0) return false;
        $ds = -$dq - $dr;

        // exclude orthogonals first
        if ($dq == 0 || $dr == 0 || $ds == 0) return false;

        return ($dq == $dr) || ($dr == $ds) || ($dq == $ds);
    }

    private function isKnightMove($dq, $dr) {
        foreach ($this->knightJumps() as [$mq, $mr]) {
            if ($dq == $mq && $dr == $mr) {
                return true;
            }
        }
        return false;
    }

    private function isKingMove($dq, $dr) {
        // King can move one step in any of the 12 directions
        $ds = -$dq - $dr;

        // one in orthogonal
        if ($this->isOrthogonalMove($dq, $dr)) {
            return max(abs($dq), abs($dr), abs($ds)) == 1;
        }

        // one in diagonal
        foreach ($this->diagonalUnitDirs() as [$uq,$ur]) {
            if ($dq == $uq && $dr == $ur) return true;
        }

        return false;
    }

    // === PATH CHECKING (integer unit steps; no rounding) ===

    private function isPathClear($fromQ, $fromR, $toQ, $toR) {
        $dq = $toQ - $fromQ;
        $dr = $toR - $fromR;

        // Determine unit step and number of steps
        $dir = $this->unitStepFor($dq, $dr);
        if ($dir === null) return false; // not on a straight hex line we support

        [$stepQ, $stepR, $steps] = $dir;

        if ($steps <= 1) return true;

        $q = $fromQ;
        $r = $fromR;
        for ($i = 1; $i < $steps; $i++) {
            $q += $stepQ;
            $r += $stepR;
            if ($this->getPiece($q, $r) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve integer unit step and step count for a straight move (orth or diag).
     * Returns [unitQ, unitR, steps] or null if not straight.
     */
    private function unitStepFor($dq, $dr) {
        if ($dq == 0 && $dr == 0) return null;
        $ds = -$dq - $dr;

        // Orthogonals
        if ($dr == 0 && $dq != 0) {
            return [$this->sgn($dq), 0, abs($dq)];
        }
        if ($dq == 0 && $dr != 0) {
            return [0, $this->sgn($dr), abs($dr)];
        }
        if ($dq + $dr == 0) { // s-constant
            // along (-1,1) or (1,-1)
            return [-$this->sgn($dr), $this->sgn($dr), abs($dr)];
        }

        // Diagonals (true hex bishops)
        if ($this->isDiagonalMoveHex($dq, $dr)) {
            // Case 1: dq == dr -> unit (1,1)
            if ($dq == $dr) {
                return [$this->sgn($dq), $this->sgn($dr), abs($dq)];
            }
            // Case 2: dr == ds -> dq == -2*dr -> unit (2,-1) or (-2,1)
            if ($dr == $ds) {
                return [ -2 * $this->sgn($dr), 1 * $this->sgn($dr), abs($dr) ];
            }
            // Case 3: dq == ds -> dr == -2*dq -> unit (1,-2) or (-1,2)
            if ($dq == $ds) {
                return [ 1 * $this->sgn($dq), -2 * $this->sgn($dq), abs($dq) ];
            }
        }

        return null;
    }

    private function sgn($x) { return ($x > 0) ? 1 : -1; }

    // === PAWN MOVEMENT LOGIC ===

    // Auto-detect per-player forward as the orthogonal direction that moves their king closest to board center.
    private function getForwardIndexForPlayer($player) {
        if (array_key_exists($player, $this->pawnForwardIndex)) {
            return $this->pawnForwardIndex[$player];
        }
        $dirs = $this->orthogonalUnitDirs(); // 6 unit orth directions in ring order
        $king = $this->findKing($player);

        // Fallback if king missing (e.g., during restore): choose reasonable axis by player id
        if (!$king) {
            $fallback = [
                0 => 0, // east  (1,0)
                1 => 3, // west (-1,0)
                2 => 2, // SW   (-1,1)
                3 => 5, // NE   (1,-1)
                4 => 1, // SE   (0,1)
                5 => 4, // NW   (0,-1)
            ];
            $idx = $fallback[$player] ?? 0;
            return $this->pawnForwardIndex[$player] = $idx;
        }

        $bestIdx = 0;
        $bestDist = PHP_INT_MAX;
        for ($i = 0; $i < 6; $i++) {
            [$dq,$dr] = $dirs[$i];
            $tq = $king['q'] + $dq;
            $tr = $king['r'] + $dr;
            $d  = $this->hexDistance($tq, $tr, 0, 0);
            if ($d < $bestDist) {
                $bestDist = $d;
                $bestIdx  = $i;
            }
        }
        return $this->pawnForwardIndex[$player] = $bestIdx;
    }

    // Given a forward index, return the two adjacent capture directions (ahead-left, ahead-right)
    private function captureDirsFromForwardIndex($forwardIdx) {
        $dirs = $this->orthogonalUnitDirs();
        $leftIdx  = ($forwardIdx + 5) % 6; // one step counter-clockwise
        $rightIdx = ($forwardIdx + 1) % 6; // one step clockwise
        return [$dirs[$leftIdx], $dirs[$rightIdx]];
    }

    private function getPawnForwardDirections($player) {
        $dirs = $this->orthogonalUnitDirs();
        $i = $this->getForwardIndexForPlayer($player);
        // single-forward variant; return as array-of-arrays to preserve API
        return [ $dirs[$i] ];
    }

private function getPawnCaptureDirections($player) {
    $dirs = $this->orthogonalUnitDirs();
    $forwardIdx = $this->getForwardIndexForPlayer($player);
    $forwardDir = $dirs[$forwardIdx];
    
    // Get the two diagonal directions that are "forward-adjacent"
    $diagonals = $this->diagonalUnitDirs();
    $captures = [];
    
    // Find diagonal directions that have the same "forward component"
    foreach ($diagonals as $diag) {
        // Check if this diagonal is in the same forward direction
        if (($forwardDir[0] > 0 && $diag[0] > 0) || 
            ($forwardDir[0] < 0 && $diag[0] < 0) || 
            ($forwardDir[0] == 0 && (
                ($forwardDir[1] > 0 && $diag[1] > 0) || 
                ($forwardDir[1] < 0 && $diag[1] < 0)
            ))) {
            $captures[] = $diag;
        }
    }
    
    // Should return exactly 2 capture directions
    return array_slice($captures, 0, 2);
}

    private function canPawnMove($piece, $fromQ, $fromR, $toQ, $toR) {
        $dq = $toQ - $fromQ;
        $dr = $toR - $fromR;
        $targetPiece = $this->getPiece($toQ, $toR);

        // forward(s)
        foreach ($this->getPawnForwardDirections($piece->player) as [$fq,$fr]) {
            // One step forward (must be empty)
            if ($dq == $fq && $dr == $fr) {
                return !$targetPiece;
            }

            // Two steps forward from starting position (must be clear)
            if ($dq == 2*$fq && $dr == 2*$fr) {
                if ($this->isPawnStartingPosition($piece->player, $fromQ, $fromR)) {
                    return !$targetPiece && !$this->getPiece($fromQ + $fq, $fromR + $fr);
                }
            }
        }

        // Diagonal captures: two adjacent directions around forward
        foreach ($this->getPawnCaptureDirections($piece->player) as [$cq,$cr]) {
            if ($dq == $cq && $dr == $cr) {
                return $targetPiece && $targetPiece->player !== $piece->player;
            }
        }

        return false;
    }

    private function isPawnStartingPosition($player, $q, $r) {
        // Slightly safer edges using s too
        $s = -$q - $r;
        $edge = $this->boardSize - 2;
        return (abs($q) >= $edge) || (abs($r) >= $edge) || (abs($s) >= $edge);
    }

    // === CHECK DETECTION ===

    protected function isKingInCheck($player) {
        $kingPos = $this->findKing($player);
        if (!$kingPos) return false;

        $attackers = [];
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize);
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {

                $piece = $this->getPiece($q, $r);
                if ($piece && $piece->player !== $player && $this->activePlayers[$piece->player]) {
                    if ($this->canPieceMoveTo($piece, $q, $r, $kingPos['q'], $kingPos['r'])) {
                        $attackers[] = "{$piece->type} at ($q,$r)";
                    }
                }
            }
        }

        $inCheck = !empty($attackers);

        // Debug output for console (not error log)
        if ($inCheck) {

error_log("KING CHECK: Player $player king at ({$kingPos['q']},{$kingPos['r']}) is in check from: " . implode(", ", $attackers));
//            echo "<script>console.log('KING CHECK: Player $player king at ({$kingPos['q']},{$kingPos['r']}) is in check from: " . implode(", ", $attackers) . "');</script>";
        }

        return $inCheck;
    }

    protected function findKing($player) {
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

    public function wouldLeaveKingInCheck($fromQ, $fromR, $toQ, $toR, $player) {
        $piece = $this->board[$fromQ][$fromR];
        $capturedPiece = $this->board[$toQ][$toR];

        // Make the move temporarily
        $this->board[$toQ][$toR] = $piece;
        $this->board[$fromQ][$fromR] = null;

        // Check if king is STILL in check after this move
        $stillInCheck = $this->isKingInCheck($player);

        // Restore the board
        $this->board[$fromQ][$fromR] = $piece;
        $this->board[$toQ][$toR] = $capturedPiece;

        // Debug output for console for bishop moves
        if ($piece && $piece->type === 'bishop') {
error_log("BISHOP CHECK DEBUG: Move ($fromQ,$fromR)->($toQ,$toR) would " .
     ($stillInCheck ? "LEAVE king in check" : "RESOLVE/PREVENT check") .
     " for player $player");
//                 echo "<script>console.log('BISHOP CHECK DEBUG: Move ($fromQ,$fromR)->($toQ,$toR) would " .
//                 ($stillInCheck ? "LEAVE king in check" : "RESOLVE/PREVENT check") . " for player $player');</script>";
        }

        return $stillInCheck;
    }



private function checkForEliminations() {
    // Check for checkmate eliminations
    for ($player = 0; $player < $this->playerCount; $player++) {
        if ($this->activePlayers[$player] && $this->isCheckmate($player)) {
            $this->activePlayers[$player] = false;
            error_log("CHECKMATE: Player $player eliminated");
        }
    }
    
    // Check if only one player remains active (multiplayer)
    $activeCount = array_sum($this->activePlayers);
    if ($activeCount == 1) {
        for ($p = 0; $p < $this->playerCount; $p++) {
            if ($this->activePlayers[$p]) {
                error_log("LAST PLAYER: Player $p wins");
                $this->endGame($p, 'elimination');
                return;
            }
        }
    }
}

// Add this to override BaseChess's game state to include check highlighting
/*
public function getGameState() {
    $baseState = parent::getGameState();
    
    // Find kings in check for highlighting
    $kingsInCheck = [];
    for ($player = 0; $player < $this->playerCount; $player++) {
        if ($this->activePlayers[$player]) {
            $kingPos = $this->findKing($player);
            if ($kingPos && $this->isKingInCheck($player)) {
                $kingsInCheck[] = $kingPos;
                error_log("CHECK HIGHLIGHT: Player $player king at ({$kingPos['q']},{$kingPos['r']})");
            }
        }
    }
    
    $baseState['kingsInCheck'] = $kingsInCheck;
    return $baseState;
}
*/

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
    
    // DEBUG: Log current state
    $activePlayerCount = $this->getActivePlayerCount();
    error_log("DEBUG getGameState: activePlayerCount = $activePlayerCount");
    error_log("DEBUG getGameState: activePlayers = " . json_encode($this->activePlayers));
    error_log("DEBUG getGameState: playerUsers = " . json_encode($this->playerUsers));
    error_log("DEBUG getGameState: baseState gameStatus = " . json_encode($baseState['gameStatus']));
    
    // Check for game over conditions
    $gameOver = $activePlayerCount <= 1;
    $winner = null;
    $reason = null;
    
    if ($gameOver && $activePlayerCount == 1) {
        // Find the last active player as winner
        for ($player = 0; $player < $this->playerCount; $player++) {
            if ($this->activePlayers[$player]) {
                $winner = $this->playerUsers[$player];
                $reason = 'checkmate'; // Should be checkmate, not elimination
                error_log("DEBUG: Found winner - player $player, user " . $this->playerUsers[$player]);
                break;
            }
        }
    } elseif ($gameOver && $activePlayerCount == 0) {
        // All players resigned or eliminated - no winner
        $winner = null;
        $reason = 'draw';
        error_log("DEBUG: No winner - all players eliminated");
    }
    
    error_log("DEBUG: Final winner = $winner, reason = $reason");
    
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
    $baseState['isInCheck'] = $this->activePlayers[$this->currentPlayer] ?
        $this->isKingInCheck($this->currentPlayer) : false;
        
    return $baseState;
}

    // === PIECE SETUPS (expanded) ===
    private function setupPieces() {
        switch ($this->playerCount) {
            case 2:  $this->setupTwoPlayerPieces();  break;
            case 3:  $this->setupThreePlayerPieces();break;
            case 4:  $this->setupFourPlayerPieces(); break;
            case 5:  $this->setupFivePlayerPieces(); break;
            case 6:  $this->setupSixPlayerPieces();  break;
            default: $this->setupTwoPlayerPieces();
        }
    }

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

        // Player 2 (Blue) - top corner
        $this->placePiece($this->boardSize, -$this->boardSize, new Piece('king', 1));
        $this->placePiece($this->boardSize-1, -$this->boardSize, new Piece('rook', 1));
        $this->placePiece($this->boardSize-2, -$this->boardSize+1, new Piece('knight', 1));
        $this->placePiece($this->boardSize-3, -$this->boardSize+2, new Piece('pawn', 1));
        $this->placePiece($this->boardSize, -$this->boardSize+1, new Piece('rook', 1));
        $this->placePiece($this->boardSize-1, -$this->boardSize+1, new Piece('queen', 1));
        $this->placePiece($this->boardSize-2, -$this->boardSize, new Piece('bishop', 1));
        $this->placePiece($this->boardSize-3, -$this->boardSize+1, new Piece('pawn', 1));
        $this->placePiece($this->boardSize, -$this->boardSize+2, new Piece('bishop', 1));
        $this->placePiece($this->boardSize-1, -$this->boardSize+2, new Piece('knight', 1));
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

            // Player 3 (Purple) - Southeast
            3 => [
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

            // Player 4 (Orange) - Southwest
            4 => [
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

    private function setupSixPlayerPieces() {
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
                'rook1' => [1, -$this->boardSize],
                'rook2' => [-1, -$this->boardSize+1],
                'bishop1' => [1, -$this->boardSize+1],
                'bishop2' => [-2, -$this->boardSize+2],
                'bishop3' => [0, -$this->boardSize+2],
                'knight1' => [2, -$this->boardSize+1],
                'knight2' => [-1, -$this->boardSize+2],
                'pawns' => [
                    [3, -$this->boardSize+3], [2, -$this->boardSize+2], [1, -$this->boardSize+2],
                    [0, -$this->boardSize+3], [-3, -$this->boardSize+3], [-2, -$this->boardSize+2],
                    [-1, -$this->boardSize+1]
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
            ],

            // Player 5 (Orange) - Southwest
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
        foreach ($positions as $player => $pieceTypes) {
            foreach ($pieceTypes as $type => $data) {
                if ($type === 'pawns') {
                    foreach ($data as $pos) {
                        $this->placePiece($pos[0], $pos[1], new Piece('pawn', $player));
                    }
                } else {
                    $this->placePiece($data[0], $data[1], new Piece(
                        str_replace(['1', '2', '3'], '', $type),
                        $player
                    ));
                }
            }
        }
    }

    private function placePiece($q, $r, $piece) {
        if ($this->isValidHex($q, $r)) {
            if (!isset($this->board[$q])) { $this->board[$q] = []; } // ensure column exists
            $this->board[$q][$r] = $piece;
        }
    }

    // === MOVE DIRECTION SETS ===

    private function orthogonalUnitDirs() {
        // axial: (1,0), (0,1), (-1,1), (-1,0), (0,-1), (1,-1)
        return [[1,0],[0,1],[-1,1],[-1,0],[0,-1],[1,-1]];
    }

    private function diagonalUnitDirs() {
        // true hex bishop unit steps
        return [[1,1],[-1,-1],[2,-1],[-2,1],[1,-2],[-1,2]];
    }

    private function knightJumps() {
        return [
            [2, 1], [3, -1], [1, 2], [-1, 3], [-2, 3], [-3, 2],
            [-3, 1], [-2, -1], [-1, -2], [1, -3], [2, -3], [3, -2]
        ];
    }

    private function pushRay($fromQ, $fromR, $dq, $dr, array &$moves) {
        $q = $fromQ + $dq;
        $r = $fromR + $dr;

        while ($this->isValidHex($q, $r)) {
            $target = $this->getPiece($q, $r);
            if ($target) {
                if ($target->player !== $this->currentPlayer && $this->isValidMove($fromQ, $fromR, $q, $r)) {
                    $moves[] = ['q' => $q, 'r' => $r];
                }
                break; // blocked
            } else {
                if ($this->isValidMove($fromQ, $fromR, $q, $r)) {
                    $moves[] = ['q' => $q, 'r' => $r];
                }
            }
            $q += $dq;
            $r += $dr;
        }
    }

    // === FOG OF WAR IMPLEMENTATION ===

    private function getFogOfWarBoard($viewerPlayer) {
        $visibleBoard = [];

        // Initialize empty board
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize);
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                if (!isset($visibleBoard[$q])) {
                    $visibleBoard[$q] = [];
                }
                $visibleBoard[$q][$r] = null;
            }
        }

        // Show own pieces and visible enemy pieces
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize);
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {

                $piece = $this->getPiece($q, $r);

                if ($piece) {
                    if ($piece->player === $viewerPlayer) {
                        // Show own pieces
                        $visibleBoard[$q][$r] = $piece;
                    } elseif ($this->isPieceVisible($q, $r, $viewerPlayer)) {
                        // Show visible enemy pieces
                        $visibleBoard[$q][$r] = $piece;
                    }
                }
            }
        }

        return $visibleBoard;
    }

    private function isPieceVisible($q, $r, $viewerPlayer) {
        // Piece is visible if it's adjacent to or attacked by viewer's pieces
        for ($fq = -$this->boardSize; $fq <= $this->boardSize; $fq++) {
            for ($fr = max(-$this->boardSize, -$fq - $this->boardSize);
                 $fr <= min($this->boardSize, -$fq + $this->boardSize); $fr++) {

                $viewerPiece = $this->getPiece($fq, $fr);
                if ($viewerPiece && $viewerPiece->player === $viewerPlayer) {
                    // Check if adjacent (within 1 hex)
                    $distance = $this->hexDistance($q, $r, $fq, $fr);
                    if ($distance <= 1) {
                        return true;
                    }

                    // Check if attacked by this piece
                    if ($this->canPieceMoveTo($viewerPiece, $fq, $fr, $q, $r)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function isValidPawnDropPosition($playerSlot, $q, $r) {
        // Prevent instant checkmate with pawn drops
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

        $this->board[$q][$r] = null;
        return !$wouldCauseCheckmate;
    }

    private function isCheckmate($player) {
        return $this->isKingInCheck($player) && !$this->hasLegalMoves($player);
    }

    // === IMPLEMENT BASECHESS SERIALIZATION METHODS ===

    protected function getVariantSpecificData() {
        // Serialize board to JSON-safe arrays; rebuild full grid so shape is preserved
        $packed = [];
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize);
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                if (!isset($packed[$q])) { $packed[$q] = []; }
                $p = $this->getPiece($q, $r);
                if ($p instanceof Piece) {
                    $packed[$q][$r] = ['type' => $p->type, 'player' => $p->player];
                } else {
                    $packed[$q][$r] = null;
                }
            }
        }

        return [
            'board'         => $packed,
            'boardSize'     => $this->boardSize,
            'players'       => $this->players,
            'activePlayers' => $this->activePlayers,
            'playerUsers'   => $this->playerUsers
        ];
    }

    protected function restoreVariantSpecificData($data) {
        $this->boardSize = isset($data['boardSize']) ? (int)$data['boardSize'] : ($this->boardSize ?: 8);

        // Rebuild grid & restore Piece objects from arrays/stdClass
        $this->board = [];
        for ($q = -$this->boardSize; $q <= $this->boardSize; $q++) {
            for ($r = max(-$this->boardSize, -$q - $this->boardSize);
                 $r <= min($this->boardSize, -$q + $this->boardSize); $r++) {
                if (!isset($this->board[$q])) { $this->board[$q] = []; }
                $cell = $data['board'][$q][$r] ?? null;

                if (is_array($cell) && isset($cell['type'], $cell['player'])) {
                    $this->board[$q][$r] = new Piece($cell['type'], (int)$cell['player']);
                } elseif (is_object($cell) && isset($cell->type, $cell->player)) { // stdClass case
                    $this->board[$q][$r] = new Piece($cell->type, (int)$cell->player);
                } else {
                    $this->board[$q][$r] = null;
                }
            }
        }

        // Restore additional fields
        $pc = isset($data['activePlayers']) ? count($data['activePlayers']) : ($this->playerCount ?? 2);
        $this->players       = $data['players']       ?? $this->players;
        $this->activePlayers = $data['activePlayers'] ?? array_fill(0, $pc, true);
        $this->playerUsers   = $data['playerUsers']   ?? array_fill(0, $pc, null);

        // Reset pawn forward cache (recompute lazily on demand)
        $this->pawnForwardIndex = [];
    }

    // === ADDITIONAL UTILITY ===

    public function getCellColor($q, $r) {
        $colorIndex = (($q - $r) % 3 + 3) % 3;
        $colors = ['light', 'medium', 'dark'];
        return $colors[$colorIndex];
    }

    // === DEBUGGING SUPPORT ===

    public function debugBishopMove($fromQ, $fromR, $toQ, $toR) {
        $piece = $this->getPiece($fromQ, $fromR);

        if (!$piece || $piece->type !== 'bishop') {
            echo "<script>console.log('DEBUG: Not a bishop move');</script>";
            return;
        }

        echo "<script>console.log('=== BISHOP MOVE DEBUG ===');</script>";
        echo "<script>console.log('Bishop at ($fromQ,$fromR) trying to move to ($toQ,$toR)');</script>";
        echo "<script>console.log('Current player: {$this->currentPlayer}');</script>";
        echo "<script>console.log('Move count: {$this->moveCount}');</script>";

        $dq = $toQ - $fromQ;
        $dr = $toR - $fromR;
        $isDiagonal = $this->isDiagonalMoveHex($dq, $dr);
        $unit = $this->unitStepFor($dq, $dr);
        $pathClear = $unit ? $this->isPathClear($fromQ, $fromR, $toQ, $toR) : false;

        echo "<script>console.log('Is diag(hex): " . ($isDiagonal ? "YES" : "NO") . "');</script>";
        if ($unit) {
            echo "<script>console.log('Unit step: (".$unit[0].",".$unit[1].") / steps ".$unit[2]."');</script>";
        } else {
            echo "<script>console.log('Unit step: NONE');</script>";
        }
        echo "<script>console.log('Path clear: " . ($pathClear ? "YES" : "NO") . "');</script>";

        $currentlyInCheck = $this->isKingInCheck($this->currentPlayer);
        echo "<script>console.log('Currently in check: " . ($currentlyInCheck ? "YES" : "NO") . "');</script>";

        $wouldLeaveInCheck = $this->wouldLeaveKingInCheck($fromQ, $fromR, $toQ, $toR, $this->currentPlayer);
        echo "<script>console.log('Would leave in check: " . ($wouldLeaveInCheck ? "YES" : "NO") . "');</script>";

        $finalValid = $this->isValidMove($fromQ, $fromR, $toQ, $toR);
        echo "<script>console.log('Final isValidMove result: " . ($finalValid ? "VALID" : "INVALID") . "');</script>";

        echo "<script>console.log('=== END BISHOP DEBUG ===');</script>";
    }
}
?>
