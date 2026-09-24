<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=labmineral', 'root', '');
    echo "Connected successfully to labmineral";
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
