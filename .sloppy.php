<?php

// Static analysis for AI-written code: `vendor/bin/sloppy` (see heyosseus/sloppy).
// The Laravel rules (SL2xx) stay off: they assume Eloquent, while Statamic's
// Site/Taxonomy/Entry facades read config and the flat-file Stache.
return [
    'paths' => ['src'],
];
