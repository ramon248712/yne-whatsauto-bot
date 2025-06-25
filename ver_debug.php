<?php
header('Content-Type: text/plain');
if (file_exists("debug_post.txt")) {
    readfile("debug_post.txt");
} else {
    echo "No existe debug_post.txt.";
}
