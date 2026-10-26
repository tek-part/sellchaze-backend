<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ArticleController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:articles-list', ['only' => ['index', 'show']]);
        $this->middleware('permission:articles-create', ['only' => ['create', 'store']]);
        $this->middleware('permission:articles-edit', ['only' => ['edit', 'update']]);
        $this->middleware('permission:articles-delete', ['only' => ['destroy']]);
    }

    public function index(Request $request)
    {
        $query = Article::with('author')->latest();

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($qry) use ($q) {
                $qry->where('title', 'like', "%{$q}%")
                    ->orWhere('excerpt', 'like', "%{$q}%");
            });
        }

        $articles = $query->paginate(15);

        return view('pages.admin.articles.index', compact('articles'))
            ->with('title', __('Articles'))
            ->with('breadcrumb', __('Articles'));
    }

    public function create()
    {
        return view('pages.admin.articles.create')
            ->with('title', __('Add Article'))
            ->with('breadcrumb', __('Articles'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['nullable', 'string'],
            'featured_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'published' => ['nullable', 'boolean'],
            'published_at' => ['nullable', 'date'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
        ]);

        $slug = Str::slug($request->title);
        if (Article::where('slug', $slug)->exists()) {
            $slug = $slug . '-' . uniqid();
        }

        $article = new Article();
        $article->slug = $slug;
        $article->title = $request->title;
        $article->excerpt = $request->excerpt;
        $article->content = $request->content;
        $article->published = (bool) $request->published;
        $article->published_at = $request->published_at;
        $article->meta_title = $request->meta_title;
        $article->meta_description = $request->meta_description;
        $article->created_by = auth()->id();

        if ($request->hasFile('featured_image')) {
            $article->featured_image = $request->file('featured_image')->store('articles', 'public');
        }

        $article->save();

        return redirect()->route('admin.articles.index')
            ->with('success', __('Article created successfully.'));
    }

    public function show(Article $article)
    {
        return redirect()->route('admin.articles.edit', $article);
    }

    public function edit(Article $article)
    {
        return view('pages.admin.articles.edit', compact('article'))
            ->with('title', __('Edit Article'))
            ->with('breadcrumb', __('Articles'));
    }

    public function update(Request $request, Article $article)
    {
        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['nullable', 'string'],
            'featured_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'published' => ['nullable', 'boolean'],
            'published_at' => ['nullable', 'date'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
        ]);

        $article->title = $request->title;
        $article->excerpt = $request->excerpt;
        $article->content = $request->content;
        $article->published = (bool) $request->published;
        $article->published_at = $request->published_at;
        $article->meta_title = $request->meta_title;
        $article->meta_description = $request->meta_description;

        if ($request->hasFile('featured_image')) {
            $article->featured_image = $request->file('featured_image')->store('articles', 'public');
        }

        $article->save();

        return redirect()->route('admin.articles.index')
            ->with('success', __('Article updated successfully.'));
    }

    public function destroy(Article $article)
    {
        $article->delete();

        return redirect()->route('admin.articles.index')
            ->with('success', __('Article deleted successfully.'));
    }
}
