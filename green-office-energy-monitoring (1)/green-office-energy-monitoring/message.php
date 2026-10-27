<?php
// One styled page for the messages that stop a request: bad input, a reading
// that would break the meter sequence, or the database being unreachable.
// Included by conn/conn.php, so every page that talks to the database has it.

function show_message(string $heading, string $detail, ?string $backLink = null, int $status = 400): void
{
    if (!headers_sent()) {
        http_response_code($status);
    }

    $heading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $detail  = htmlspecialchars($detail,  ENT_QUOTES, 'UTF-8');
    $back    = $backLink === null ? '' :
        '<a class="back" href="' . htmlspecialchars($backLink, ENT_QUOTES, 'UTF-8') . '">Back to Green Office</a>';

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$heading</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Avenir Next', 'Segoe UI', sans-serif; }

        body {
            background: #f4f7f3;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .message-box {
            background-color: #ffffff;
            border-radius: 10px;
            border-top: 5px solid #c62828;
            padding: 32px;
            max-width: 520px;
            border: 1px solid #dfe8e1;
        }

        h1 { font-size: 1.2rem; font-weight: 600; color: #c62828; margin-bottom: 10px; }
        p  { font-size: 0.95rem; color: #444444; line-height: 1.55; }

        .back {
            display: inline-block;
            margin-top: 22px;
            background-color: #174d3c;
            color: #ffffff;
            text-decoration: none;
            padding: 9px 18px;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .back:hover { background-color: #287655; }
        .back:focus-visible { outline: 3px solid #578b32; outline-offset: 3px; }
    </style>
</head>
<body>
    <div class="message-box">
        <h1>$heading</h1>
        <p>$detail</p>
        $back
    </div>
</body>
</html>
HTML;

    exit();
}
