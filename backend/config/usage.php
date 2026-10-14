<?php

/*
| Prices used by the owner portal's usage page (USD). Keep them in line with the providers'
| price pages: https://developers.openai.com/api/docs/pricing and
| https://developers.facebook.com/docs/whatsapp/pricing (Lebanon = "Rest of Middle East").
*/
return [

    // Per 1M tokens: input, cached input, output.
    'ai_models' => [
        'gpt-4.1-mini' => ['input' => 0.40, 'cached' => 0.10, 'output' => 1.60],
        'gpt-4.1-nano' => ['input' => 0.10, 'cached' => 0.025, 'output' => 0.40],
        'gpt-4o-mini' => ['input' => 0.15, 'cached' => 0.075, 'output' => 0.60],
        'gpt-5-mini' => ['input' => 0.25, 'cached' => 0.025, 'output' => 2.00],
        'gpt-5-nano' => ['input' => 0.05, 'cached' => 0.005, 'output' => 0.40],
        // Voice notes: input = audio tokens (about $0.003 per minute).
        'gpt-4o-mini-transcribe' => ['input' => 3.00, 'cached' => 3.00, 'output' => 5.00],
    ],

    // Meta bills these to the shop's own WhatsApp account, not to NeoTalab.
    'whatsapp' => [
        'free_service_messages' => (int) env('WA_FREE_SERVICE_MESSAGES', 1000), // per number per month
        'service_rate' => (float) env('WA_SERVICE_RATE', 0.0091),
        'template_rate' => (float) env('WA_TEMPLATE_RATE', 0.0091), // utility
    ],

];
