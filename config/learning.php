<?php

return [
    'allow_preview' => (bool) env('NTHANDA_ALLOW_PREVIEW', env('APP_ENV', 'production') !== 'production'),
];
