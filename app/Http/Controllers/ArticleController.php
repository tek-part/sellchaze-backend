<?php

namespace App\Http\Controllers;

use App\Models\Article;

class ArticleController extends Controller
{
    public function index()
    {
        $articles = Article::published()
            ->with('author')
            ->latest('published_at')
            ->paginate(12);

        return view('pages.blog.index', array_merge(compact('articles'), [
            'bodyClass' => 'blog-v1',
            'pageCss' => 'blog-v1.css',
        ]));
    }

    public function show(string $slug)
    {
        $article = Article::published()
            ->where('slug', $slug)
            ->with('author')
            ->firstOrFail();

        return view('pages.blog.show', array_merge(compact('article'), [
            'bodyClass' => 'blog-details',
            'pageCss' => 'blog-details.css',
        ]));
    }
}
