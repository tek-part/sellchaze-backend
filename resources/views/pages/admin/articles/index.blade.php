<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">{{ __('Articles') }}</h4>
                        </div>
                        <div class="col-auto">
                            @can('articles-create')
                            <a href="{{ route('admin.articles.create') }}" class="btn btn-primary">
                                <i class="las la-plus me-1"></i> {{ __('Add Article') }}
                            </a>
                            @endcan
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    @if (session('success'))
                        <div class="alert alert-success alert-dismissible fade show mb-3">
                            {{ session('success') }}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    @endif

                    <form method="GET" class="mb-4">
                        <div class="input-group" style="max-width: 300px;">
                            <input type="text" name="q" class="form-control" placeholder="{{ __('Search') }}..." value="{{ request('q') }}">
                            <button class="btn btn-primary" type="submit">{{ __('Search') }}</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>{{ __('Title') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Author') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th class="text-end">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($articles as $article)
                                <tr>
                                    <td>
                                        <a href="{{ route('blog.show', $article->slug) }}" target="_blank" class="text-dark text-decoration-none">{{ $article->title }}</a>
                                    </td>
                                    <td>
                                        @if($article->published)
                                            <span class="badge bg-success">{{ __('Published') }}</span>
                                        @else
                                            <span class="badge bg-secondary">{{ __('Draft') }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $article->author?->name ?? '-' }}</td>
                                    <td>{{ $article->created_at->format('Y-m-d') }}</td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            @can('articles-edit')
                                            <a href="{{ route('admin.articles.edit', $article) }}" class="btn btn-light">
                                                <i class="las la-edit"></i>
                                            </a>
                                            @endcan
                                            @can('articles-delete')
                                            <form action="{{ route('admin.articles.destroy', $article) }}" method="POST" class="d-inline" onsubmit="return confirm('{{ __('Are you sure?') }}');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-light text-danger">
                                                    <i class="las la-trash-alt"></i>
                                                </button>
                                            </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">{{ __('No articles yet.') }}</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-center mt-3">
                        {{ $articles->withQueryString()->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-default-layout>
