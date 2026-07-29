<?php

if (PHP_SAPI !== 'cli') {
    exit(2);
}

sleep(10);
echo '{"result":"ok","reason_code":"unexpected_completion"}';
