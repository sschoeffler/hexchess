<?php
function renderBoard($game, $gameId, $canMove) {
    $boardSize = $game->getBoardSize();
    $html = '<div class="hex-board">';
    
    for ($r = -$boardSize; $r <= $boardSize; $r++) {
        $html .= '<div class="hex-row">';
        $qMin = max(-$boardSize, -$r - $boardSize);
        $qMax = min($boardSize, -$r + $boardSize);
        
        for ($q = $qMin; $q <= $qMax; $q++) {
            $piece = $game->getPiece($q, $r);
            $colorClass = getCellColor($q, $r);
            
            $html .= '<div class="hex-cell ' . $colorClass . '" data-q="' . $q . '" data-r="' . $r . '" onclick="selectHex(' . $q . ', ' . $r . ')">';
            $html .= '<div class="hex-content">';
            
            if ($piece) {
                $pieceClass = getPieceClass($piece->player);
                // ONLY add data-piece attribute - NO text content inside the span
                $html .= '<span class="piece ' . $pieceClass . '" data-piece="' . $piece->type . '"></span>';
            }
            
            $html .= '</div></div>';
        }
        $html .= '</div>';
    }
    
    $html .= '</div>';
    return $html;
}

// ADD THIS MISSING FUNCTION - This is what actions.php is looking for
function renderGameBoard($game) {
    return renderBoard($game, '', true); // Call the main function with default values
}

function renderDemoBoard($game, $pieceType) {
    $boardSize = $game->getBoardSize();
    $html = '<div class="hex-board">';
    
    for ($r = -$boardSize; $r <= $boardSize; $r++) {
        $html .= '<div class="hex-row">';
        $qMin = max(-$boardSize, -$r - $boardSize);
        $qMax = min($boardSize, -$r + $boardSize);
        
        for ($q = $qMin; $q <= $qMax; $q++) {
            $piece = $game->getPiece($q, $r);
            $colorClass = getCellColor($q, $r);
            
            $html .= '<div class="hex-cell ' . $colorClass . '" data-q="' . $q . '" data-r="' . $r . '" onclick="selectHex(' . $q . ', ' . $r . ')">';
            $html .= '<div class="hex-content">';
            
            if ($piece) {
                $pieceClass = getPieceClass($piece->player);
                // ONLY add data-piece attribute - NO text content inside the span
                $html .= '<span class="piece ' . $pieceClass . '" data-piece="' . $piece->type . '"></span>';
            }
            
            $html .= '</div></div>';
        }
        $html .= '</div>';
    }
    
    $html .= '</div>';
    return $html;
}

function createDemoBoard() {
    // Create a demo game with a single piece for demonstration
    $game = new HexChess('demo', 2, 8);
    // Clear the board
    $game->clearBoard();
    // Place a single red king in the center for demo
    $game->placeDemoPiece(0, 0, new Piece('king', 0));
    return $game;
}

function getCellColor($q, $r) {
    $colorIndex = (($q - $r) % 3 + 3) % 3;
    $colors = ['pastel-red', 'pastel-green', 'pastel-blue'];
    return $colors[$colorIndex];
}

function getPieceClass($player) {
    $classes = ['red-piece', 'blue-piece', 'green-piece', 'yellow-piece', 'purple-piece', 'orange-piece'];
    return $classes[$player] ?? 'red-piece';
}

// Remove the getPieceSymbol function since we're not using text content anymore
?>