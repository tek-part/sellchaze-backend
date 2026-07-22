<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">{{ __('Edit Article') }}</h4>
                </div>
                <div class="card-body">
                    @if ($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show mb-4">
                            <ul class="mb-0 ps-3">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    <form action="{{ route('admin.articles.update', $article) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')
                        <div class="row g-4">
                            <div class="col-12">
                                <label for="title" class="form-label fw-semibold">{{ __('Title') }} *</label>
                                <input type="text" name="title" id="title" class="form-control" value="{{ old('title', $article->title) }}" required>
                                <div class="form-text">{{ __('Slug') }}: {{ $article->slug }}</div>
                            </div>
                            <div class="col-12">
                                <label for="excerpt" class="form-label fw-semibold">{{ __('Excerpt') }}</label>
                                <textarea name="excerpt" id="excerpt" class="form-control" rows="2" maxlength="500">{{ old('excerpt', $article->excerpt) }}</textarea>
                            </div>
                            <div class="col-12">
                                <label for="content" class="form-label fw-semibold">{{ __('Content') }}</label>
                                <textarea name="content" id="content" class="form-control" rows="12">{{ old('content', $article->content) }}</textarea>
                            </div>
                            <div class="col-md-6">
                                <label for="featured_image" class="form-label fw-semibold">{{ __('Featured image') }}</label>
                                @if($article->featured_image)
                                    <div class="mb-2">
                                        <img src="{{ asset('storage/' . $article->featured_image) }}" alt="" style="max-height: 120px;" class="rounded">
                                    </div>
                                @endif
                                <input type="file" name="featured_image" id="featured_image" class="form-control" accept="image/*">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">{{ __('Status') }}</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="published" id="published" value="1" {{ old('published', $article->published) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="published">{{ __('Published') }}</label>
                                </div>
                                <div class="mt-2">
                                    <label for="published_at" class="form-label small">{{ __('Publish date') }}</label>
                                    <input type="datetime-local" name="published_at" id="published_at" class="form-control form-control-sm" value="{{ old('published_at', $article->published_at?->format('Y-m-d\TH:i')) }}">
                                </div>
                            </div>
                            <div class="col-12">
                                <hr>
                                <h5 class="mb-3">{{ __('SEO') }}</h5>
                            </div>
                            <div class="col-md-6">
                                <label for="meta_title" class="form-label fw-semibold">{{ __('Meta title') }}</label>
                                <input type="text" name="meta_title" id="meta_title" class="form-control" value="{{ old('meta_title', $article->meta_title) }}">
                            </div>
                            <div class="col-12">
                                <label for="meta_description" class="form-label fw-semibold">{{ __('Meta description') }}</label>
                                <textarea name="meta_description" id="meta_description" class="form-control" rows="2" maxlength="500">{{ old('meta_description', $article->meta_description) }}</textarea>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-4 pt-3 border-top">
                            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                            <a href="{{ route('blog.show', $article->slug) }}" class="btn btn-light" target="_blank">{{ __('View') }}</a>
                            <a href="{{ route('admin.articles.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-default-layout>
