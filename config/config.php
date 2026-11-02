<?php

return [
    /*
     * HTTPS URL (or local path) of the Git repository holding the blog content.
     */
    'repository' => env('BLOGSMD_REPOSITORY', ''),

    /*
     * Branch that is published.
     */
    'branch' => env('BLOGSMD_BRANCH', 'master'),

    /*
     * Folder in the repository holding posts. Subfolders become part of the post URL.
     */
    'posts_path' => 'posts',

    /*
     * Folder in the repository holding category files (categories/<slug>.md).
     */
    'categories_path' => 'categories',

    /*
     * Which identity of the commit that first added a post is credited: "author" or "committer".
     */
    'author_field' => 'author',

    /*
     * What happens to a post whose file is removed from the repository: "delete" or "unpublish".
     */
    'on_removed' => 'delete',

    /*
     * Create a 301 redirect through Winter.Redirect (when installed) when a post's URL changes.
     */
    'redirect_on_move' => true,

    /*
     * URL path of the blog post page, without the post slug. Used for redirects.
     */
    'post_url_prefix' => '/blog',

    /*
     * Secret shared with the GitHub webhook. The webhook refuses every request while it is empty.
     */
    'webhook_secret' => env('BLOGSMD_WEBHOOK_SECRET', ''),

    /*
     * Optional access token for private repositories. Never written to the remote URL or logs.
     */
    'token' => env('BLOGSMD_TOKEN', ''),
];
