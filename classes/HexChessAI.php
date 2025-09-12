<?php

class HexChessAI {
    private $game;
    private $difficulty;
    private $playerSlot;

    // Piece values for evaluation
    private $pieceValues = [
        'pawn' => 1,
        'knight' => 3,
        'bishop' => 3,
        'rook' => 5,
        'queen' => 9,
        'king' => 1000
    ];

    public function __construct($game, $difficulty = 'basic', $playerSlot = 1) {
        $this->game = $game;
        $this->difficulty = $this->normalizeDifficulty($difficulty);
        $this->playerSlot = (int)$playerSlot;
    }

    private function getMoveCount(): int {
        // Prefer a real getter if HexChess exposes one
        if (method_exists($this->game, 'getMoveCount')) {
            return (int)$this->game->getMoveCount();
        }
        // Fallback: pull from game state if provided
        if (method_exists($this->game, 'getGameState')) {
            $state = $this->game->getGameState();
            if (isset($state['moveCount'])) return (int)$state['moveCount'];
        }
        // Last resort: 0
        return 0;
    }

    private function normalizeDifficulty($d) {
        $x = strtolower(trim($d));
        $x = preg_replace('/[^a-z]/', '', $x);
        $map = [
            'advanced' => 'player',
            'playerai' => 'player',
            'player'   => 'player',
            'medium'   => 'basic',
            'basicai'  => 'basic',
            'basic'    => 'basic',
            'easy'     => 'easy',
            'hard'     => 'hard',
        ];
        return $map[$x] ?? 'basic';
    }

    /**
     * Get the best move for the current AI player
     */
    public function getBestMove() {
        $validMoves = $this->getAllValidMoves();

        if (empty($validMoves)) {
            return null; // No legal moves
        }

        switch ($this->difficulty) {
            case 'easy':
                return $this->getRandomMove($validMoves);

            case 'basic':
                return $this->getBasicMove($validMoves);

            case 'player':
                return $this->getPlayerMove($validMoves);

            case 'hard':
                // Previous "hard" used direct board mutation (private property) -> 500.
                // Until we add a public simulate API on the game, fall back to stronger heuristic.
                return $this->getPlayerMove($validMoves);

            default:
                return $this->getBasicMove($validMoves);
        }
    }

    /**
     * Easy AI - Random legal moves
     */
    private function getRandomMove($validMoves) {
        return $validMoves[array_rand($validMoves)];
    }

    /**
     * Basic AI - Basic position evaluation
     */
    private function getBasicMove($validMoves) {
        $bestMove = null;
        $bestScore = -9999;

        foreach ($validMoves as $move) {
            $score = $this->evaluateMove($move);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMove = $move;
            }
        }

        return $bestMove ?: $this->getRandomMove($validMoves);
    }

    /**
     * Player AI - With threat detection, tactical awareness, and defensive play
     */
    private function getPlayerMove($validMoves) {
        $bestMove = null;
        $bestScore = -9999;

        // First, check if any of our pieces are under immediate threat
        $threatenedPieces = $this->findThreatenedPieces();

        // Group moves by type (optional, for debugging/logging)
        $captureMoves = [];
        $defensiveMoves = [];

        foreach ($validMoves as $move) {
            $capturedPiece = $this->game->getPiece($move['toQ'], $move['toR']);
            $movingPiece = $this->game->getPiece($move['fromQ'], $move['fromR']);

            if ($capturedPiece) {
                $captureMoves[] = $move;
            } elseif ($this->isSavingThreatenedPiece($move, $threatenedPieces)) {
                $defensiveMoves[] = $move;
            }

            $score = $this->evaluatePlayerMove($move, $threatenedPieces);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMove = $move;
            }
        }

        // Log AI reasoning for debugging
        $this->logAIThinking($threatenedPieces, $captureMoves, $defensiveMoves);

        return $bestMove ?: $this->getBasicMove($validMoves);
    }

    /**
     * Find all our pieces that are currently under attack with exchange analysis
     */
    private function findThreatenedPieces() {
        $threatenedPieces = [];

        // Check every position on the board for our pieces
        for ($q = -$this->game->getBoardSize(); $q <= $this->game->getBoardSize(); $q++) {
            for ($r = max(-$this->game->getBoardSize(), -$q - $this->game->getBoardSize());
                 $r <= min($this->game->getBoardSize(), -$q + $this->game->getBoardSize()); $r++) {

                $piece = $this->game->getPiece($q, $r);
                if ($piece && $piece->player === $this->playerSlot) {
                    // Check if this piece is threatened by any enemy piece
                    $attackingPieces = $this->getPiecesAttacking($q, $r);
                    if (!empty($attackingPieces)) {
                        $defenderValue = $this->pieceValues[$piece->type];
                        $lowestAttackerValue = min(array_column($attackingPieces, 'value'));
                        $isBadExchange = ($defenderValue > $lowestAttackerValue);

                        $threatenedPieces[] = [
                            'q' => $q,
                            'r' => $r,
                            'piece' => $piece,
                            'value' => $defenderValue,
                            'attackers' => $attackingPieces,
                            'priority' => $isBadExchange ? 'high' : 'low'
                        ];
                    }
                }
            }
        }

        // Sort by priority then by value
        usort($threatenedPieces, function($a, $b) {
            if ($a['priority'] !== $b['priority']) {
                return ($a['priority'] === 'high') ? -1 : 1;
            }
            return $b['value'] - $a['value'];
        });

        return $threatenedPieces;
    }

    /**
     * Get all enemy pieces attacking a position
     */
    private function getPiecesAttacking($q, $r) {
        $attackers = [];

        for ($eq = -$this->game->getBoardSize(); $eq <= $this->game->getBoardSize(); $eq++) {
            for ($er = max(-$this->game->getBoardSize(), -$eq - $this->game->getBoardSize());
                 $er <= min($this->game->getBoardSize(), -$eq + $this->game->getBoardSize()); $er++) {

                $enemyPiece = $this->game->getPiece($eq, $er);
                if ($enemyPiece && $enemyPiece->player !== $this->playerSlot) {
                    if ($this->canPieceCaptureTarget($enemyPiece, $eq, $er, $q, $r)) {
                        $attackers[] = [
                            'piece' => $enemyPiece,
                            'value' => $this->pieceValues[$enemyPiece->type],
                            'q' => $eq,
                            'r' => $er
                        ];
                    }
                }
            }
        }

        return $attackers;
    }

    /**
     * Player move evaluation with threat detection, tactics, and strategy
     * Includes: destination safety & exchange analysis. NO direct board mutation.
     */
    private function evaluatePlayerMove($move, $threatenedPieces) {
        error_log("DEBUG: evaluatePlayerMove called for move: " . json_encode($move));
        $score = 0;

        $capturedPiece = $this->game->getPiece($move['toQ'], $move['toR']);
        if ($capturedPiece && $capturedPiece->player !== $this->playerSlot) {
            $score += $this->pieceValues[$capturedPiece->type];
            if ($this->pieceValues[$capturedPiece->type] >= 5) {
                $score += 2; // bonus for rook/queen
            }
        }

        // Destination safety (don’t move valuable piece onto a square cheap enemy can capture)
        $movingPiece = $this->game->getPiece($move['fromQ'], $move['fromR']);
        if ($movingPiece) {
            $destinationAttackers = $this->getPiecesAttacking($move['toQ'], $move['toR']);
            if (!empty($destinationAttackers)) {
                $ourPieceValue = $this->pieceValues[$movingPiece->type];
                $cheapestAttacker = min(array_column($destinationAttackers, 'value'));
                if ($ourPieceValue > $cheapestAttacker) {
                    $score += ($ourPieceValue - $cheapestAttacker) * -2;
                    if ($ourPieceValue >= 5) {
                        $score -= 8;
                    }
                }
            }
        }

        // Exchange quality on captures
        if ($capturedPiece && $movingPiece) {
            $ourPieceValue = $this->pieceValues[$movingPiece->type];
            $capturedValue = $this->pieceValues[$capturedPiece->type];
            $destinationAttackers = $this->getPiecesAttacking($move['toQ'], $move['toR']);

            if (!empty($destinationAttackers)) {
                $exchangeValue = $capturedValue - $ourPieceValue;
                if ($exchangeValue < 0) {
                    $score += $exchangeValue * 1.5;
                    if ($ourPieceValue >= 9 && $capturedValue <= 3) {
                        $score -= 10;
                    }
                } else if ($exchangeValue > 0) {
                    $score += $exchangeValue * 0.5;
                }
            }
        }

        // Saving threatened piece
        if ($movingPiece) {
            foreach ($threatenedPieces as $threatened) {
                if ($threatened['q'] == $move['fromQ'] && $threatened['r'] == $move['fromR']) {
                    $score += $threatened['value'] * 2;
                    if ($threatened['value'] >= 5) $score += 5;
                    break;
                }
            }
        }

        // Fork potential (approx: evaluate from TO-square without mutating board)
        $score += $this->evaluateForkPotential($move);

        // Protecting other threatened pieces (adjacency heuristic)
        foreach ($threatenedPieces as $threatened) {
            if ($this->moveProtectsPiece($move, $threatened['q'], $threatened['r'])) {
                $score += $threatened['value'] * 0.5;
            }
        }

        // Counter-attack bonus
        if ($capturedPiece) {
            foreach ($threatenedPieces as $threatened) {
                if ($this->isPieceAttackedBy($threatened['q'], $threatened['r'], $move['toQ'], $move['toR'])) {
                    $score += 3;
                    break;
                }
            }
        }

        // King safety: use the game's public "wouldLeaveKingInCheck" instead of mutating board
        if ($this->wouldExposeKing($move)) {
            $score -= 10;
        }

        // Development bonus early game
        if ($this->getMoveCount() < 20) {
            $centerDistance = abs($move['toQ']) + abs($move['toR']) + abs($move['toQ'] + $move['toR']);
            $score += (10 - $centerDistance) * 0.08;
        }

        // Small fuzz to avoid determinism
        $score += (rand(0, 100) / 1000);

        return $score;
    }

    /**
     * Check if our piece will be defended at destination (kept for possible UI/tooling)
     */
    private function isDestinationDefended($move) {
        $defenders = [];
        $movingPiece = $this->game->getPiece($move['fromQ'], $move['fromR']);
        if (!$movingPiece) return false;

        for ($q = -$this->game->getBoardSize(); $q <= $this->game->getBoardSize(); $q++) {
            for ($r = max(-$this->game->getBoardSize(), -$q - $this->game->getBoardSize());
                 $r <= min($this->game->getBoardSize(), -$q + $this->game->getBoardSize()); $r++) {

                $defender = $this->game->getPiece($q, $r);
                if ($defender && $defender->player === $this->playerSlot &&
                    !($q == $move['fromQ'] && $r == $move['fromR'])) {

                    if ($this->canPieceCaptureTarget($defender, $q, $r, $move['toQ'], $move['toR'])) {
                        $defenders[] = [
                            'piece' => $defender,
                            'value' => $this->pieceValues[$defender->type]
                        ];
                    }
                }
            }
        }

        return !empty($defenders);
    }

    /**
     * Evaluate tactical fork potential from TO-square without mutating board
     */
    private function evaluateForkPotential($move) {
        $forkBonus = 0;
        $movingPiece = $this->game->getPiece($move['fromQ'], $move['fromR']);
        if (!$movingPiece) return 0;

        $attackableEnemies = [];
        for ($q = -$this->game->getBoardSize(); $q <= $this->game->getBoardSize(); $q++) {
            for ($r = max(-$this->game->getBoardSize(), -$q - $this->game->getBoardSize());
                 $r <= min($this->game->getBoardSize(), -$q + $this->game->getBoardSize()); $r++) {

                $enemyPiece = $this->game->getPiece($q, $r);
                if ($enemyPiece && $enemyPiece->player !== $this->playerSlot) {
                    if ($this->canPieceCaptureTarget($movingPiece, $move['toQ'], $move['toR'], $q, $r)) {
                        $attackableEnemies[] = [
                            'piece' => $enemyPiece,
                            'value' => $this->pieceValues[$enemyPiece->type]
                        ];
                    }
                }
            }
        }

        if (count($attackableEnemies) >= 2) {
            $forkBonus += 4;
            $totalValue = array_sum(array_column($attackableEnemies, 'value'));
            if ($totalValue >= 10) $forkBonus += 3;
        }

        return $forkBonus;
    }

    /**
     * Evaluate a single move (Basic AI)
     */
    private function evaluateMove($move) {
        $score = 0;

        $capturedPiece = $this->game->getPiece($move['toQ'], $move['toR']);
        if ($capturedPiece && $capturedPiece->player !== $this->playerSlot) {
            $score += $this->pieceValues[$capturedPiece->type];
        }

        // Center control bonus
        $centerDistance = abs($move['toQ']) + abs($move['toR']) + abs($move['toQ'] + $move['toR']);
        $score += (10 - $centerDistance) * 0.1;

        // King safety – use public check instead of mutating board
        if ($this->wouldExposeKing($move)) {
            $score -= 5;
        }

        // Slight randomness
        $score += (rand(0, 100) / 100) * 0.5;

        return $score;
    }

    /**
     * Evaluate the entire board position (quick heuristic; no protected calls)
     */
    private function evaluatePosition() {
        $score = 0;

        for ($q = -$this->game->getBoardSize(); $q <= $this->game->getBoardSize(); $q++) {
            for ($r = max(-$this->game->getBoardSize(), -$q - $this->game->getBoardSize());
                 $r <= min($this->game->getBoardSize(), -$q + $this->game->getBoardSize()); $r++) {

                $piece = $this->game->getPiece($q, $r);
                if ($piece) {
                    $pieceValue = $this->pieceValues[$piece->type];

                    if ($piece->player === $this->playerSlot) {
                        $score += $pieceValue;
                    } else {
                        $score -= $pieceValue;
                    }

                    // Position bonuses
                    $score += $this->getPositionBonus($piece, $q, $r);
                }
            }
        }

        // Mobility: our moves minus opponent moves (assume 2-player)
        $ourMoves = count($this->getAllValidMovesForPlayer($this->playerSlot));
        $theirMoves = count($this->getAllValidMovesForPlayer($this->getOpponentSlot()));
        $score += ($ourMoves - $theirMoves) * 0.1;

        return $score;
    }

    private function getOpponentSlot(): int {
        // Assume 2-player indexing [0,1]
        return $this->playerSlot === 0 ? 1 : 0;
    }

    /**
     * Position bonus for piece placement
     */
    private function getPositionBonus($piece, $q, $r) {
        $bonus = 0;
        $centerDistance = abs($q) + abs($r) + abs($q + $r);

        switch ($piece->type) {
            case 'pawn':
                if ($piece->player === $this->playerSlot) {
                    $bonus += ($this->game->getBoardSize() - $centerDistance) * 0.1;
                }
                break;

            case 'knight':
            case 'bishop':
                $bonus += (10 - $centerDistance) * 0.05;
                break;

            case 'king':
                $bonus += $centerDistance * 0.02;
                break;
        }

        return $piece->player === $this->playerSlot ? $bonus : -$bonus;
    }

    /**
     * King-exposure check using game's public method (no board writes)
     */
    private function wouldExposeKing($move) {
        return $this->game->wouldLeaveKingInCheck(
            $move['fromQ'], $move['fromR'],
            $move['toQ'],   $move['toR'],
            $this->playerSlot
        );
    }

    /**
     * Check if a move protects a piece (blocks attack or defends it)
     */
    private function moveProtectsPiece($move, $protectedQ, $protectedR) {
        $dq = abs($move['toQ'] - $protectedQ);
        $dr = abs($move['toR'] - $protectedR);
        $ds = abs(($move['toQ'] + $move['toR']) - ($protectedQ + $protectedR));
        return max($dq, $dr, $ds) == 1;
    }

    /**
     * Check if a piece is being attacked by a specific enemy piece
     */
    private function isPieceAttackedBy($pieceQ, $pieceR, $attackerQ, $attackerR) {
        $attacker = $this->game->getPiece($attackerQ, $attackerR);
        if (!$attacker || $attacker->player === $this->playerSlot) {
            return false;
        }
        return $this->canPieceCaptureTarget($attacker, $attackerQ, $attackerR, $pieceQ, $pieceR);
    }

    /**
     * Check if a move saves a threatened piece
     */
    private function isSavingThreatenedPiece($move, $threatenedPieces) {
        foreach ($threatenedPieces as $threatened) {
            if ($threatened['q'] == $move['fromQ'] && $threatened['r'] == $move['fromR']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Log AI decision making for debugging
     */
    private function logAIThinking($threatenedPieces, $captureMoves, $defensiveMoves) {
        if (empty($threatenedPieces) && empty($captureMoves) && empty($defensiveMoves)) {
            return;
        }
        $logMessage = "AI analysis: ";
        if (!empty($threatenedPieces)) {
            $threatCount = count(array_filter($threatenedPieces, function($t) { return $t['priority'] === 'high'; }));
            $logMessage .= "{$threatCount} high-priority threats, ";
        }
        if (!empty($captureMoves)) {
            $logMessage .= count($captureMoves) . " capture opportunities, ";
        }
        if (!empty($defensiveMoves)) {
            $logMessage .= count($defensiveMoves) . " defensive options";
        }
        error_log(rtrim($logMessage, ', '));
    }

    /**
     * (Disabled) Minimax algorithm previously mutated private $board; not used now.
     * Keep stub for future when a public simulation API exists on the game.
     */
    // private function minimax(...) { /* intentionally disabled */ }

    /**
     * Can a piece capture a target (hex movement, uses board reads only)
     */
    private function canPieceCaptureTarget($piece, $fromQ, $fromR, $targetQ, $targetR) {
        $dq = $targetQ - $fromQ;
        $dr = $targetR - $fromR;

        switch ($piece->type) {
            case 'pawn':
                // Use variant-accurate pawn captures (two diagonals adjacent to forward)
                foreach ($this->getPawnCaptureDirections($piece->player) as [$cq, $cr]) {
                    if ($dq == $cq && $dr == $cr) return true;
                }
                return false;

            case 'knight':
                foreach ($this->knightJumps() as [$mq, $mr]) {
                    if ($dq == $mq && $dr == $mr) return true;
                }
                return false;

            case 'bishop':
                return $this->isDiagonalMove($dq, $dr) && $this->isPathClear($fromQ, $fromR, $targetQ, $targetR);

            case 'rook':
                return $this->isOrthogonalMove($dq, $dr) && $this->isPathClear($fromQ, $fromR, $targetQ, $targetR);

            case 'queen':
                return ($this->isOrthogonalMove($dq, $dr) || $this->isDiagonalMove($dq, $dr))
                    && $this->isPathClear($fromQ, $fromR, $targetQ, $targetR);

            case 'king':
                // Any adjacent hex (12 directions)
                $ds = -$dq - $dr;
                if (max(abs($dq), abs($dr), abs($ds)) == 1) return true;
                return false;
        }

        return false;
    }

    // ====== Geometry helpers (consistent with your HexChess ruleset) ======

    private function isOrthogonalMove($dq, $dr) {
        return ($dr == 0) || ($dq == 0) || ($dq == -$dr);
    }

    private function isDiagonalMove($dq, $dr) {
        $ds = -$dq - $dr;
        if ($dq == 0 || $dr == 0 || $ds == 0) return false; // exclude orthogonals
        return ($dq == $dr) || ($dr == $ds) || ($dq == $ds);
    }

    private function isPathClear($fromQ, $fromR, $toQ, $toR) {
        $dq = $toQ - $fromQ;
        $dr = $toR - $fromR;
        $ds = -$dq - $dr;

        // straight-line distance in cube coords
        $steps = max(abs($dq), abs($dr), abs($ds));
        if ($steps <= 1) return true;

        // normalize integer step using gcd
        $g = function ($a, $b) {
            $a = abs((int)$a); $b = abs((int)$b);
            while ($b) { $t = $b; $b = $a % $t; $a = $t; }
            return $a ?: 1;
        };
        $g3 = $g($g($dq, $dr), $ds);
        if ($g3 == 0) return false;

        $stepQ = (int)($dq / $g3);
        $stepR = (int)($dr / $g3);

        $q = (int)$fromQ;
        $r = (int)$fromR;
        for ($i = 1; $i < $steps; $i++) {
            $q += $stepQ;
            $r += $stepR;
            if ($this->game->getPiece($q, $r) !== null) return false;
        }
        return true;
    }

    private function knightJumps() {
        return [
            [2, 1], [3, -1], [1, 2], [-1, 3], [-2, 3], [-3, 2],
            [-3, 1], [-2, -1], [-1, -2], [1, -3], [2, -3], [3, -2]
        ];
    }

    private function orthogonalUnitDirs() {
        // axial ring order
        return [[1,0],[0,1],[-1,1],[-1,0],[0,-1],[1,-1]];
    }

    private function hexDistance($q1,$r1,$q2,$r2) {
        $dq = $q1 - $q2;
        $dr = $r1 - $r2;
        $ds = -$dq - $dr;
        return max(abs($dq), abs($dr), abs($ds));
    }

    // ====== Pawn forward/capture modeling for AI (matches HexChess logic) ======

    private function getPawnForwardIndexForPlayer($player) {
        // Derive forward as the orth direction that moves that side's king closest to center
        $king = $this->findKingForPlayer($player);
        if (!$king) {
            // sensible fallback if no king found
            $fallback = [
                0 => 0, // east
                1 => 3, // west
                2 => 2, // SW
                3 => 5, // NE
                4 => 1, // SE
                5 => 4, // NW
            ];
            return $fallback[$player] ?? 0;
        }
        $dirs = $this->orthogonalUnitDirs();
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
        return $bestIdx;
    }

    private function getPawnCaptureDirections($player) {
        $dirs = $this->orthogonalUnitDirs();
        $i = $this->getPawnForwardIndexForPlayer($player);
        $leftIdx  = ($i + 5) % 6;
        $rightIdx = ($i + 1) % 6;
        return [$dirs[$leftIdx], $dirs[$rightIdx]];
    }

    private function findKingForPlayer($player) {
        for ($q = -$this->game->getBoardSize(); $q <= $this->game->getBoardSize(); $q++) {
            for ($r = max(-$this->game->getBoardSize(), -$q - $this->game->getBoardSize());
                 $r <= min($this->game->getBoardSize(), -$q + $this->game->getBoardSize()); $r++) {
                $p = $this->game->getPiece($q, $r);
                if ($p && $p->type === 'king' && $p->player === $player) {
                    return ['q' => $q, 'r' => $r];
                }
            }
        }
        return null;
    }

    /**
     * Get all valid moves for current AI player
     */
    private function getAllValidMoves() {
        return $this->getAllValidMovesForPlayer($this->playerSlot);
    }

    /**
     * Get all valid moves for a specific player
     */
    private function getAllValidMovesForPlayer($player) {
        $moves = [];

        for ($q = -$this->game->getBoardSize(); $q <= $this->game->getBoardSize(); $q++) {
            for ($r = max(-$this->game->getBoardSize(), -$q - $this->game->getBoardSize());
                 $r <= min($this->game->getBoardSize(), -$q + $this->game->getBoardSize()); $r++) {

                $piece = $this->game->getPiece($q, $r);
                if ($piece && $piece->player === $player) {
                    // Assumes the game exposes a public getValidMoves($q,$r)
                    $pieceMoves = $this->game->getValidMoves($q, $r);

                    foreach ($pieceMoves as $m) {
                        $moves[] = [
                            'fromQ' => $q,
                            'fromR' => $r,
                            'toQ' => $m['q'],
                            'toR' => $m['r']
                        ];
                    }
                }
            }
        }

        return $moves;
    }

    /**
     * Make the AI move
     */
    public function makeMove() {
        $move = $this->getBestMove();

        if ($move) {
            error_log("AI ({$this->difficulty}) making move: ({$move['fromQ']},{$move['fromR']}) -> ({$move['toQ']},{$move['toR']})");
            return $this->game->movePiece($move['fromQ'], $move['fromR'], $move['toQ'], $move['toR']);
        }

        return false;
    }

    /**
     * Get AI thinking time
     */
    public function getThinkingTime() {
        switch ($this->difficulty) {
            case 'easy':
                return rand(500, 1500);
            case 'basic':
                return rand(1000, 3000);
            case 'player':
                return rand(2000, 4000);
            case 'hard':
                return rand(2000, 5000);
            default:
                return rand(1000, 2000);
        }
    }

    /**
     * Get AI personality name
     */
    public function getAIName() {
        $names = [
            'easy' => ['Rookie', 'Cadet', 'Novice', 'Learner', 'Scout'],
            'basic' => ['Knight', 'Captain', 'Warrior', 'Guardian', 'Sergeant'],
            'player' => ['Rook', 'Fortress', 'Tower', 'Defender', 'Tactician', 'Strategist'],
            'hard' => ['Master', 'Grandmaster', 'Champion', 'Legend', 'Overlord']
        ];
        $difficultyNames = $names[$this->difficulty] ?? $names['basic'];
        return $difficultyNames[array_rand($difficultyNames)];
    }
}
