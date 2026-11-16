<?php

namespace App\Game\Bots\Llm;

/** Failure talking to an LLM provider or invalid provider settings; the message is safe to show (API key masked). */
class LlmException extends \Exception {}
