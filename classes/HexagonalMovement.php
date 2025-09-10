trait HexagonalMovement {
    
    /**
     * Check if coordinates are valid on the hex board
     */
    protected function isValidHex($q, $r) {
        $s = -$q - $r;
        return abs($q) <= $this->boardSize && 
               abs($r) <= $this->boardSize && 
               abs($s) <= $this->boardSize;
    }
    
    /**
     * Get neighboring hex coordinates
     */
    protected function getHexNeighbors($q, $r) {
        $directions = [
            [1, 0], [1, -1], [0, -1],
            [-1, 0], [-1, 1], [0, 1]
        ];
        
        $neighbors = [];
        foreach ($directions as $dir) {
            $nq = $q + $dir[0];
            $nr = $r + $dir[1];
            
            if ($this->isValidHex($nq, $nr)) {
                $neighbors[] = ['q' => $nq, 'r' => $nr];
            }
        }
        
        return $neighbors;
    }
}
