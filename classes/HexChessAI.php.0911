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
    // strip punctuation/extra spaces to catch “Player AI”, etc.
    $x = preg_replace('/[^a-z]/', '', $x);
    // back-compat map
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

/*    public function __construct($game, $difficulty = 'basic', $playerSlot = 1) {
        $this->game = $game;
        $this->difficulty = $difficulty;
        $this->playerSlot = $playerSlot;
    }
*/    
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
                return $this->getHardMove($validMoves);
                
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
     * ENHANCED: Player AI - With threat detection, tactical awareness, and defensive play
     */
    private function getPlayerMove($validMoves) {
        $bestMove = null;
        $bestScore = -9999;
        
        // First, check if any of our pieces are under immediate threat
        $threatenedPieces = $this->findThreatenedPieces();
        
        // Group moves by type for better decision making
        $captureMoves = [];
        $defensiveMoves = [];
        $developmentMoves = [];
        
        foreach ($validMoves as $move) {
            $capturedPiece = $this->game->getPiece($move['toQ'], $move['toR']);
            $movingPiece = $this->game->getPiece($move['fromQ'], $move['fromR']);
            
            if ($capturedPiece) {
                $captureMoves[] = $move;
            } elseif ($this->isSavingThreatenedPiece($move, $threatenedPieces)) {
                $defensiveMoves[] = $move;
            } else {
                $developmentMoves[] = $move;
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
     * Hard AI - Minimax with limited depth
     */
    private function getHardMove($validMoves) {
        $bestMove = null;
        $bestScore = -9999;
        
        foreach ($validMoves as $move) {
            // Simulate the move
            $originalPiece = $this->game->getPiece($move['toQ'], $move['toR']);
            $movingPiece = $this->game->getPiece($move['fromQ'], $move['fromR']);
            
            // Make temporary move
            $this->game->board[$move['toQ']][$move['toR']] = $movingPiece;
            $this->game->board[$move['fromQ']][$move['fromR']] = null;
            
            // Evaluate position after move
            $score = $this->minimax(2, false, -10000, 10000);
            
            // Undo move
            $this->game->board[$move['fromQ']][$move['fromR']] = $movingPiece;
            $this->game->board[$move['toQ']][$move['toR']] = $originalPiece;
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMove = $move;
            }
        }
        
        return $bestMove ?: $this->getBasicMove($validMoves);
    }
    
    /**
     * ENHANCED: Find all our pieces that are currently under attack with exchange analysis
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
                        // Calculate if this is a favorable exchange
                        $defenderValue = $this->pieceValues[$piece->type];
                        $lowestAttackerValue = min(array_column($attackingPieces, 'value'));
                        
                        // Only consider it a real threat if the exchange is unfavorable
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
        
        // Sort by priority (bad exchanges first) then by piece value (most valuable first)
        usort($threatenedPieces, function($a, $b) {
            if ($a['priority'] !== $b['priority']) {
                return ($a['priority'] === 'high') ? -1 : 1;
            }
            return $b['value'] - $a['value'];
        });
        
        return $threatenedPieces;
    }
    
    /**
     * NEW: Get all enemy pieces attacking a position
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
     * ENHANCED: Player move evaluation with threat detection, tactics, and strategy
     * NOW INCLUDES: Destination Safety Analysis & Exchange Analysis for Captures
     */
    private function evaluatePlayerMove($move, $threatenedPieces) {
        error_log("DEBUG: evaluatePlayerMove called for move: " . json_encode($move)); // ADD THIS
        $score = 0;
   
        // Basic capture value
        $capturedPiece = $this->game->getPiece($move['toQ'], $move['toR']);
        if ($capturedPiece && $capturedPiece->player !== $this->playerSlot) {
            $score += $this->pieceValues[$capturedPiece->type];
            
            // BONUS: High-value captures get extra points
            if ($this->pieceValues[$capturedPiece->type] >= 5) {
                $score += 2; // Extra bonus for rook/queen captures
            }
        }
        
        // ===== NEW: DESTINATION SAFETY ANALYSIS =====
        // Check if we're moving into an attacked square (prevents losing pieces for nothing)
        $movingPiece = $this->game->getPiece($move['fromQ'], $move['fromR']);
        if ($movingPiece) {
            $destinationAttackers = $this->getPiecesAttacking($move['toQ'], $move['toR']);
            
            if (!empty($destinationAttackers)) {
                $ourPieceValue = $this->pieceValues[$movingPiece->type];
                $cheapestAttacker = min(array_column($destinationAttackers, 'value'));
                
                // If they can capture us with a cheaper piece, this is a bad move
                if ($ourPieceValue > $cheapestAttacker) {
                    $dangerPenalty = ($ourPieceValue - $cheapestAttacker) * -2; // Heavy penalty
                    $score += $dangerPenalty;
                    
                    // CRITICAL: Don't move valuable pieces to attacked squares unless justified
                    if ($ourPieceValue >= 5) { // Rook or Queen
                        $score -= 8; // Extra penalty for endangering major pieces
                    }
                }
            }
        }
        
        // ===== NEW: EXCHANGE ANALYSIS FOR CAPTURES =====
        // When capturing, calculate if the exchange is favorable
        if ($capturedPiece && $movingPiece) {
            $ourPieceValue = $this->pieceValues[$movingPiece->type];
            $capturedValue = $this->pieceValues[$capturedPiece->type];
            
            // Check if destination is defended after capture
            $destinationAttackers = $this->getPiecesAttacking($move['toQ'], $move['toR']);
            
            if (!empty($destinationAttackers)) {
                $cheapestDefender = min(array_column($destinationAttackers, 'value'));
                
                // Calculate exchange: What we gain vs what we might lose
                $exchangeValue = $capturedValue - $ourPieceValue;
                
                if ($exchangeValue < 0) {
                    // Bad exchange: we lose more than we gain
                    $score += $exchangeValue * 1.5; // Penalty for bad trades
                    
                    // EXTRA PENALTY: Never trade Queen for Pawn/Knight/Bishop
                    if ($ourPieceValue >= 9 && $capturedValue <= 3) {
                        $score -= 10; // Massive penalty for terrible trades
                    }
                } else if ($exchangeValue > 0) {
                    // Good exchange: we gain more than we lose
                    $score += $exchangeValue * 0.5; // Bonus for favorable trades
                }
            }
        }
        
        // MAJOR BONUS: Saving a threatened piece
        if ($movingPiece) {
            foreach ($threatenedPieces as $threatened) {
                if ($threatened['q'] == $move['fromQ'] && $threatened['r'] == $move['fromR']) {
                    // This move saves a threatened piece!
                    $saveBonus = $threatened['value'] * 2; // Double the piece value as bonus
                    $score += $saveBonus;
                    
                    // Extra bonus for saving more valuable pieces
                    if ($threatened['value'] >= 5) { // Rook or Queen
                        $score += 5;
                    }
                    break;
                }
            }
        }
        
        // TACTICAL BONUS: Check for forks and double attacks
        $forkBonus = $this->evaluateForkPotential($move);
        $score += $forkBonus;
        
        // DEFENSIVE BONUS: Protecting other threatened pieces
        foreach ($threatenedPieces as $threatened) {
            if ($this->moveProtectsPiece($move, $threatened['q'], $threatened['r'])) {
                $protectBonus = $threatened['value'] * 0.5; // Half value for protecting
                $score += $protectBonus;
            }
        }
        
        // COUNTER-ATTACK BONUS: Capturing the piece that's threatening us
        if ($capturedPiece) {
            foreach ($threatenedPieces as $threatened) {
                if ($this->isPieceAttackedBy($threatened['q'], $threatened['r'], $move['toQ'], $move['toR'])) {
                    $score += 3; // Bonus for counter-attacking
                    break;
                }
            }
        }
        
        // KING SAFETY: Strong penalty for exposing king
        if ($this->wouldExposeKing($move)) {
            $score -= 10; // Increased penalty
        }
        
        // DEVELOPMENT BONUS: Moving pieces toward center early game
        if ($this->getMoveCount() < 20) { // Early game
            $centerDistance = abs($move['toQ']) + abs($move['toR']) + abs($move['toQ'] + $move['toR']);
            $score += (10 - $centerDistance) * 0.08;
        }
        
        // Small random factor to avoid completely predictable play
        $score += (rand(0, 100) / 1000); // 0-0.1 random bonus
        
        return $score;
    }

/**
 * NEW: Check if our piece will be defended at destination
 */
private function isDestinationDefended($move) {
    $defenders = [];
    $movingPiece = $this->game->getPiece($move['fromQ'], $move['fromR']);
    
    if (!$movingPiece) return false;
    
    // Look for our pieces that can defend the destination
    for ($q = -$this->game->getBoardSize(); $q <= $this->game->getBoardSize(); $q++) {
        for ($r = max(-$this->game->getBoardSize(), -$q - $this->game->getBoardSize()); 
             $r <= min($this->game->getBoardSize(), -$q + $this->game->getBoardSize()); $r++) {
            
            $defender = $this->game->getPiece($q, $r);
            if ($defender && $defender->player === $this->playerSlot && 
                !($q == $move['fromQ'] && $r == $move['fromR'])) { // Not the moving piece itself
                
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

/* end PLAYER new */


    /**
     * NEW: Evaluate tactical fork potential after a move
     */
    private function evaluateForkPotential($move) {
        $forkBonus = 0;
        $movingPiece = $this->game->getPiece($move['fromQ'], $move['fromR']);
        
        if (!$movingPiece) return 0;
        
        // Simulate the move
        $originalPiece = $this->game->getPiece($move['toQ'], $move['toR']);
        $this->game->board[$move['toQ']][$move['toR']] = $movingPiece;
        $this->game->board[$move['fromQ']][$move['fromR']] = null;
        
        // Count how many enemy pieces this piece can now attack
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
        
        // Bonus for attacking multiple pieces (fork)
        if (count($attackableEnemies) >= 2) {
            $forkBonus += 4; // Base fork bonus
            
            // Extra bonus for high-value forks
            $totalValue = array_sum(array_column($attackableEnemies, 'value'));
            if ($totalValue >= 10) { // Fork involving major pieces
                $forkBonus += 3;
            }
        }
        
        // Undo the move
        $this->game->board[$move['fromQ']][$move['fromR']] = $movingPiece;
        $this->game->board[$move['toQ']][$move['toR']] = $originalPiece;
        
        return $forkBonus;
    }
    
    /**
     * Evaluate a single move (original for Basic AI)
     */
    private function evaluateMove($move) {
        $score = 0;
        
        // Capture value
        $capturedPiece = $this->game->getPiece($move['toQ'], $move['toR']);
        if ($capturedPiece && $capturedPiece->player !== $this->playerSlot) {
            $score += $this->pieceValues[$capturedPiece->type];
        }
        
        // Center control bonus
        $centerDistance = abs($move['toQ']) + abs($move['toR']) + abs($move['toQ'] + $move['toR']);
        $score += (10 - $centerDistance) * 0.1;
        
        // King safety - penalize moves that expose king
        if ($this->wouldExposeKing($move)) {
            $score -= 5;
        }
        
        // Random factor for less predictable play
        $score += (rand(0, 100) / 100) * 0.5;
        
        return $score;
    }
    
    /**
     * Evaluate the entire board position
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
        
        // King safety evaluation
        if ($this->game->isKingInCheck($this->playerSlot)) {
            $score -= 10;
        }
        
        // Mobility bonus
        $ourMoves = count($this->getAllValidMovesForPlayer($this->playerSlot));
//        $theirMoves = count($this->getAllValidMovesForPlayer(1 - $this->playerSlot));
$theirMoves = count($this->getAllValidMovesForPlayer($this->getOpponentSlot()));
        $score += ($ourMoves - $theirMoves) * 0.1;
        
        return $score;
    }
    
    private function getOpponentSlot(): int {
    // If your game uses 0/1, keep the ternary; if it uses 1/2, use the second line.
    // return $this->playerSlot === 0 ? 1 : 0;
    return $this->playerSlot === 1 ? 2 : 1;
}

    /**
     * Get position bonus for piece placement
     */
    private function getPositionBonus($piece, $q, $r) {
        $bonus = 0;
        $centerDistance = abs($q) + abs($r) + abs($q + $r);
        
        switch ($piece->type) {
            case 'pawn':
                // Pawns like to advance
                if ($piece->player === $this->playerSlot) {
                    $bonus += ($this->game->getBoardSize() - $centerDistance) * 0.1;
                }
                break;
                
            case 'knight':
            case 'bishop':
                // Minor pieces like center control
                $bonus += (10 - $centerDistance) * 0.05;
                break;
                
            case 'king':
                // King safety - prefer edges early game
                $bonus += $centerDistance * 0.02;
                break;
        }
        
        return $piece->player === $this->playerSlot ? $bonus : -$bonus;
    }
    
    /**
     * ENHANCED: Check if this move would expose our king to check (fixed implementation)
     */
    private function wouldExposeKing($move) {
        // Actually check if this move would expose our king to check
        $originalPiece = $this->game->getPiece($move['toQ'], $move['toR']);
        $movingPiece = $this->game->getPiece($move['fromQ'], $move['fromR']);
        
        if (!$movingPiece) return false;
        
        // Simulate the move
        $this->game->board[$move['toQ']][$move['toR']] = $movingPiece;
        $this->game->board[$move['fromQ']][$move['fromR']] = null;
        
        // Check if our king is now in check
        $kingInCheck = $this->game->isKingInCheck($this->playerSlot);
        
        // Undo the move
        $this->game->board[$move['fromQ']][$move['fromR']] = $movingPiece;
        $this->game->board[$move['toQ']][$move['toR']] = $originalPiece;
        
        return $kingInCheck;
    }
    
    /**
     * Check if a move protects a piece (blocks attack or defends it)
     */
    private function moveProtectsPiece($move, $protectedQ, $protectedR) {
        // Simple check: moving adjacent to the threatened piece provides some protection
        $dq = abs($move['toQ'] - $protectedQ);
        $dr = abs($move['toR'] - $protectedR);
        $ds = abs(($move['toQ'] + $move['toR']) - ($protectedQ + $protectedR));
        
        return max($dq, $dr, $ds) == 1; // Adjacent position
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
     * NEW: Check if a move saves a threatened piece
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
     * NEW: Log AI decision making for debugging
     */
    private function logAIThinking($threatenedPieces, $captureMoves, $defensiveMoves) {
        if (empty($threatenedPieces) && empty($captureMoves) && empty($defensiveMoves)) {
            return; // Nothing interesting to log
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
     * Minimax algorithm with alpha-beta pruning
     */
    private function minimax($depth, $maximizing, $alpha, $beta) {
        if ($depth == 0) {
            return $this->evaluatePosition();
        }
        
        $moves = $this->getAllValidMoves();
        
        if ($maximizing) {
            $maxEval = -10000;
            foreach ($moves as $move) {
                // Simulate move
                $originalPiece = $this->game->getPiece($move['toQ'], $move['toR']);
                $movingPiece = $this->game->getPiece($move['fromQ'], $move['fromR']);
                
                $this->game->board[$move['toQ']][$move['toR']] = $movingPiece;
                $this->game->board[$move['fromQ']][$move['fromR']] = null;
                
                $eval = $this->minimax($depth - 1, false, $alpha, $beta);
                
                // Undo move
                $this->game->board[$move['fromQ']][$move['fromR']] = $movingPiece;
                $this->game->board[$move['toQ']][$move['toR']] = $originalPiece;
                
                $maxEval = max($maxEval, $eval);
                $alpha = max($alpha, $eval);
                
                if ($beta <= $alpha) {
                    break; // Alpha-beta pruning
                }
            }
            return $maxEval;
        } else {
            $minEval = 10000;
            foreach ($moves as $move) {
                // Similar logic for minimizing player
                $originalPiece = $this->game->getPiece($move['toQ'], $move['toR']);
                $movingPiece = $this->game->getPiece($move['fromQ'], $move['fromR']);
                
                $this->game->board[$move['toQ']][$move['toR']] = $movingPiece;
                $this->game->board[$move['fromQ']][$move['fromR']] = null;
                
                $eval = $this->minimax($depth - 1, true, $alpha, $beta);
                
                $this->game->board[$move['fromQ']][$move['fromR']] = $movingPiece;
                $this->game->board[$move['toQ']][$move['toR']] = $originalPiece;
                
                $minEval = min($minEval, $eval);
                $beta = min($beta, $eval);
                
                if ($beta <= $alpha) {
                    break;
                }
            }
            return $minEval;
        }
    }
    
    /**
     * ENHANCED: Check if a piece can capture a target (improved for hexagonal chess)
     */
    private function canPieceCaptureTarget($piece, $fromQ, $fromR, $targetQ, $targetR) {
        $dq = $targetQ - $fromQ;
        $dr = $targetR - $fromR;
        
        switch ($piece->type) {
            case 'pawn':
                // More accurate pawn capture patterns for hex chess
                $pawnCaptures = [];
                
                // Basic diagonal captures - these are the most common
                $basicCaptures = [
                    [1, 1], [2, -1], [-1, -1], [-2, 1], [1, -2], [-1, 2]
                ];
                
                foreach ($basicCaptures as $capture) {
                    if ($dq == $capture[0] && $dr == $capture[1]) {
                        return true;
                    }
                }
                return false;
                
            case 'knight':
                $knightMoves = [
                    [2, 1], [3, -1], [1, 2], [-1, 3], [-2, 3], [-3, 2],
                    [-3, 1], [-2, -1], [-1, -2], [1, -3], [2, -3], [3, -2]
                ];
                foreach ($knightMoves as $move) {
                    if ($dq == $move[0] && $dr == $move[1]) {
                        return true;
                    }
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
                // King can move to any of the 12 adjacent hexes
                if ($this->isOrthogonalMove($dq, $dr)) {
                    $ds = -$dq - $dr;
                    return max(abs($dq), abs($dr), abs($ds)) == 1;
                }
                
                if ($this->isDiagonalMove($dq, $dr)) {
                    // Check the 6 basic diagonal moves
                    $validDiagonals = [
                        [1, 1], [-1, -1],   // dq == dr condition
                        [-2, 1], [2, -1],   // dr == ds condition  
                        [1, -2], [-1, 2],   // dq == ds condition
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
    
    // Helper methods
    private function isOrthogonalMove($dq, $dr) {
        return ($dr == 0) || ($dq == 0) || ($dq == -$dr);
    }
    
    private function isDiagonalMove($dq, $dr) {
        $ds = -$dq - $dr;
        return ($dq == $dr) || ($dr == $ds) || ($dq == $ds);
    }
    
private function isPathClear($fromQ, $fromR, $toQ, $toR) {
    $dq = $toQ - $fromQ;
    $dr = $toR - $fromR;
    $ds = -$dq - $dr;

    // Number of hexes between endpoints on a straight hex line
    $steps = max(abs($dq), abs($dr), abs($ds));
    if ($steps <= 1) return true; // adjacent or same

    // Not a straight hex line -> no clear path (caller should have checked direction already)
    // But keep a safety guard:
    if ($steps === 0) return true;

    // Integer direction increments (normalize by gcd of three components)
    $g = function ($a, $b) {
        $a = abs((int)$a); $b = abs((int)$b);
        while ($b) { $t = $b; $b = $a % $t; $a = $t; }
        return $a ?: 1;
    };
    $g3 = $g($g($dq, $dr), $ds);

    $stepQ = (int)($dq / $g3);
    $stepR = (int)($dr / $g3);

    // Walk from the square next to the origin up to (but not including) the destination
    $q = (int)$fromQ;
    $r = (int)$fromR;
    for ($i = 1; $i < $steps; $i++) {
        $q += $stepQ;
        $r += $stepR;
        if ($this->game->getPiece($q, $r) !== null) {
            return false;
        }
    }
    return true;
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
                    $pieceMoves = $this->game->getValidMoves($q, $r);
                    
                    foreach ($pieceMoves as $move) {
                        $moves[] = [
                            'fromQ' => $q,
                            'fromR' => $r,
                            'toQ' => $move['q'],
                            'toR' => $move['r']
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
                return rand(500, 1500); // 0.5-1.5 seconds
            case 'basic':
                return rand(1000, 3000); // 1-3 seconds
            case 'player':
                return rand(2000, 4000); // 2-4 seconds
            case 'hard':
                return rand(2000, 5000); // 2-5 seconds
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