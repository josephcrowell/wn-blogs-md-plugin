<?php

return [
    'plugin' => [
        'name' => 'Blogs MD',
        'description' => 'Publish Winter.Blog posts and categories from Markdown files in a Git repository.',
    ],
    'locked' => 'Blog posts and categories are managed in Git. Change them in the blog repository.',
    'managed_in_git' => 'Managed in Git',
    'view_source' => 'View source file',
    'source_commit' => 'Synced from commit',
    'settings' => [
        'description' => 'Blog repository, webhook and publishing options.',
        'tabs' => [
            'repository' => 'Repository',
            'publishing' => 'Publishing',
        ],
        'repository' => 'Repository URL',
        'repository_comment' => 'HTTPS URL of the Git repository holding the blog, e.g. https://github.com/owner/blog.',
        'branch' => 'Branch',
        'token' => 'Access token',
        'token_comment' => 'Only for private repositories. Needs read access to the repository contents.',
        'posts_path' => 'Posts folder',
        'categories_path' => 'Categories folder',
        'webhook_secret' => 'Webhook secret',
        'webhook_secret_comment' => 'The secret entered in the GitHub webhook for /webhooks/blogsmd/github. The webhook refuses every request while it is empty.',
        'author_field' => 'Credit posts to',
        'author_field_comment' => 'Use the commit author: squash merges and GitHub web edits record GitHub as the committer.',
        'author_field_author' => 'Commit author',
        'author_field_committer' => 'Committer',
        'on_removed' => 'When a post file is removed',
        'on_removed_delete' => 'Delete the post',
        'on_removed_unpublish' => 'Unpublish the post',
        'post_url_prefix' => 'Post URL prefix',
        'post_url_prefix_comment' => 'URL of the blog post page without the slug. Used for redirects.',
        'redirect_on_move' => 'Redirect moved posts',
        'redirect_on_move_comment' => 'Create a 301 redirect through Winter.Redirect when a post\'s URL changes.',
    ],
    'permissions' => [
        'tab' => 'Blogs MD',
        'access_settings' => 'Manage the blog repository settings',
    ],
];
