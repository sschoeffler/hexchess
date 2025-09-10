<?php

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