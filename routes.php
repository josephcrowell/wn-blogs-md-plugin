<?php

use Illuminate\Support\Facades\Route;
use JosephCrowell\BlogsMd\Classes\GitHubWebhook;

Route::post('/webhooks/blogsmd/github', [GitHubWebhook::class, 'handle']);
