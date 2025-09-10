<!DOCTYPE html>
<html>
<head>
    <title>🔍 Hexagon Test</title>
    <style>
        body { 
            background: #1a202c; 
            color: white; 
            font-family: Arial; 
            padding: 20px; 
        }
        
        .test-hex {
            width: 60px;
            height: 60px;
            background: #feb2b2;
            border: 2px solid #fc8181;
            margin: 10px;
            display: inline-block;
            clip-path: polygon(25% 0%, 75% 0%, 100% 50%, 75% 100%, 25% 100%, 0% 50%);
            position: relative;
        }
        
        .test-piece {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 36px;
            color: #dc2626;
        }
    </style>
</head>
<body>
    <h1>🔍 Hexagon Shape Test</h1>
    
    <h2>Test 1: Pure CSS Hexagon</h2>
    <div class="test-hex">
        <span class="test-piece">♚</span>
    </div>
    <div class="test-hex">
        <span class="test-piece">♛</span>
    </div>
    <div class="test-hex">
        <span class="test-piece">♜</span>
    </div>
    
    <p>If you see hexagons above ✅, CSS clip-path works in your browser.</p>
    <p>If you see rectangles ❌, your browser doesn't support clip-path.</p>
    
    <h2>Test 2: Browser Support Check</h2>
    <script>
        if (CSS.supports('clip-path', 'polygon(50% 0%, 0% 100%, 100% 100%)')) {
            document.write('<p style="color: green;">✅ Your browser supports clip-path</p>');
        } else {
            document.write('<p style="color: red;">❌ Your browser does NOT support clip-path</p>');
            document.write('<p>Try updating your browser or use Chrome/Firefox/Safari</p>');
        }
    </script>
    
    <h2>Test 3: Loading Your Game Styles</h2>
    <link rel="stylesheet" href="assets/styles.css">
    
    <div class="hex-cell pastel-red">
        <div class="hex-content">
            <span class="piece red-piece">♚</span>
        </div>
    </div>
    
    <p>If the piece above is in a hexagon ✅, your CSS is working.</p>
    <p>If it's a square ❌, there's a CSS conflict.</p>
    
    <hr>
    <p><a href="?page=lobby" style="color: #60a5fa;">← Back to Lobby</a></p>
    <p><a href="error_viewer.php" style="color: #60a5fa;">🔧 Error Viewer</a></p>
</body>
</html>