<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ClaudeService
{
    public function chat(string $message, string $context = ''): string
    {
        $systemPrompt = "
        You are the Lyons Bowe AI assistant.

        Use only the provided Lyons Bowe knowledge context.
        Do not give formal legal advice.
        You will always be as polite as possible.
        You will always use UK English and not American English.
        If the answer is not in the context, say that a solicitor should confirm.
        Keep answers clear, professional and helpful.
        ";

            $userPrompt = "
            Knowledge Context:
            {$context}

            User Question:
            {$message}
        ";

        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model' => config('services.anthropic.model'),
            'max_tokens' => 1000,
            'system' => $systemPrompt,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new \Exception($response->body());
        }

        $text = $response->json('content.0.text');

        if (! is_string($text) || empty(trim($text))) {
            return 'I am only able to assist with Lyons Bowe legal services relating to Property Law, Family Law, and Wills & Probate.';
        }

        return $text;
    }
}