<x-default-layout :title="$title" :breadcrumb="$breadcrumb">
    @push('rizz-css')
    <style>
        .list-action-btn { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border: none; border-radius: 0.375rem; }
        .list-action-btn i { line-height: 1; }
        .share-invite-panel {
            border-radius: 0.75rem;
            border: 1px solid var(--bs-border-color-translucent, rgba(0,0,0,.08));
            box-shadow: 0 0.125rem 0.5rem rgba(0, 0, 0, 0.04);
            overflow: hidden;
            background: var(--bs-body-bg);
        }
        .share-invite-panel__accent {
            height: 4px;
            background: linear-gradient(90deg, var(--bs-primary) 0%, color-mix(in srgb, var(--bs-primary) 65%, white) 100%);
        }
        .share-invite-panel__icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.625rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--bs-primary-bg-subtle, rgba(13, 110, 253, 0.1));
            color: var(--bs-primary);
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .share-invite-url-field {
            font-size: 0.8125rem;
            line-height: 1.45;
            resize: none;
            min-height: 3.25rem;
            max-height: 5.5rem;
            overflow-y: auto;
            word-break: break-all;
            background: var(--bs-secondary-bg, #f8f9fa) !important;
            border-color: var(--bs-border-color) !important;
        }
        .share-invite-code-box {
            font-size: 1.125rem;
            letter-spacing: 0.08em;
            font-weight: 600;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            background: var(--bs-secondary-bg, #f8f9fa);
            border: 1px dashed var(--bs-border-color);
            border-radius: 0.5rem;
            padding: 0.65rem 1rem;
            text-align: center;
            user-select: all;
        }
        @supports not (color: color-mix(in srgb, red, blue)) {
            .share-invite-panel__accent { background: var(--bs-primary); }
        }
    </style>
    @endpush
    @php
        $list = $allInvitations ?? $sentInvitations;
    @endphp
    <div class="row" data-bulk-prefix="invitations">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="card-title">
                                @if(auth()->user()->hasRole('Admin'))
                                    {{ __('All invitations') }}
                                @else
                                    {{ __('Pending invitations') }} / {{ __('Sent') }}
                                @endif
                            </h4>
                        </div>
                        <div class="col-auto">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                @if($list && $list->count())
                                    <button type="button" class="btn btn-light border" id="crm-invitations-excel-btn" title="{{ __('Export Excel') }}">
                                        <i class="fas fa-file-excel-o me-1 text-success"></i> {{ __('Excel') }}
                                    </button>
                                    @can('invitations-list')
                                        <button type="button" class="btn btn-danger" id="invitations-bulk-delete-btn" disabled data-empty-msg="{{ __('Please select at least one invitation.') }}" data-confirm-msg="{{ __('Are you sure you want to delete the selected invitations?') }}">
                                            <i class="las la-trash-alt me-1"></i> {{ __('Delete selected') }}
                                        </button>
                                    @endcan
                                @endif
                                @can('invitations-send-request')
                                    <a href="{{ route('requestInvitation') }}" class="btn btn-primary"><i class="fas fa-plus me-1"></i> {{ __('Send invitation') }}</a>
                                @endcan
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body pt-0">
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show mb-3">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif
                    @if($errors->any())
                        <div class="alert alert-danger alert-dismissible fade show mb-3">{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                    @endif

                    @if(!empty($shareInvitation))
                        @php
                            $shareRoleLabel = $shareInvitation->invited_role === 'supplier' ? __('Supplier') : ($shareInvitation->invited_role === 'merchant' ? __('Merchant') : '—');
                        @endphp
                        <div class="share-invite-panel mb-4">
                            <div class="share-invite-panel__accent" aria-hidden="true"></div>
                            <div class="p-4">
                                <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
                                    <div class="share-invite-panel__icon" aria-hidden="true">
                                        <i class="las la-user-friends"></i>
                                    </div>
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                            <h5 class="fw-bold mb-0">{{ __('Your shared invitation') }}</h5>
                                            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 fw-semibold">{{ $shareRoleLabel }}</span>
                                        </div>
                                        <p class="text-muted small mb-0 lh-base">{{ __('Share this link or code with anyone. Each person who signs up is linked to your account automatically.') }}</p>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-lg-8">
                                        <label class="form-label small fw-semibold text-body mb-2" for="share-invite-link">{{ __('Invitation link') }}</label>
                                        <div class="d-flex flex-column flex-sm-row gap-2">
                                            <textarea id="share-invite-link" class="form-control share-invite-url-field font-monospace flex-grow-1" readonly rows="2" spellcheck="false">{{ $shareInvitation->getLink() }}</textarea>
                                            <button type="button" class="btn btn-outline-primary d-inline-flex align-items-center justify-content-center gap-2 px-3 share-invite-copy-btn" id="share-invite-copy-link" data-copy-target="share-invite-link" style="min-width: 7rem;">
                                                <i class="las la-copy fs-5"></i>
                                                <span class="share-invite-copy-label">{{ __('Copy') }}</span>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-lg-4">
                                        <label class="form-label small fw-semibold text-body mb-2 d-block">{{ __('Invite code') }}</label>
                                        <div class="share-invite-code-box mb-2" id="share-invite-code-text">{{ $shareInvitation->invite_code }}</div>
                                        <button type="button" class="btn btn-outline-primary w-100 d-inline-flex align-items-center justify-content-center gap-2 share-invite-copy-btn" id="share-invite-copy-code" data-copy-text="{{ $shareInvitation->invite_code }}">
                                            <i class="las la-copy fs-5"></i>
                                            <span class="share-invite-copy-label">{{ __('Copy code') }}</span>
                                        </button>
                                    </div>
                                </div>

                                @can('invitations-delete')
                                    @if(!$shareInvitation->registered_at && (auth()->user()->hasRole('Admin') || (int)$shareInvitation->sender_user_id === (int)auth()->id()))
                                        <div class="mt-4 pt-3 border-top d-flex justify-content-end">
                                            <form action="{{ route('invitations.destroy', $shareInvitation) }}" method="POST" class="m-0" data-rizz-confirm="{{ __('Revoking will create a new link next time you open this page. Continue?') }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-link btn-sm text-danger text-decoration-none p-0 d-inline-flex align-items-center gap-1">
                                                    <i class="las la-unlink fs-5"></i>
                                                    {{ __('Revoke shared link') }}
                                                </button>
                                            </form>
                                        </div>
                                    @endif
                                @endcan
                            </div>
                        </div>
                    @endif

                    @if(auth()->user()->isMerchant() && $partnerSuppliers->isNotEmpty())
                        <h5 class="mb-3">{{ __('My suppliers') }}</h5>
                        <div class="table-responsive mb-5">
                            <table class="table mb-0 crm-datatable" data-export-name="partners-suppliers">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('User') }}</th>
                                        <th>{{ __('Email') }}</th>
                                        <th>{{ __('Partnership status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($partnerSuppliers as $u)
                                        <tr>
                                            <td class="fw-medium">{{ $u->name }}</td>
                                            <td><a href="mailto:{{ $u->email }}">{{ $u->email }}</a></td>
                                            <td><span class="badge bg-success-subtle text-success">{{ __(data_get($u->pivot, 'status', 'accepted')) }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if(auth()->user()->isSupplier() && $partnerMerchants->isNotEmpty())
                        <h5 class="mb-3">{{ __('My merchants') }}</h5>
                        <div class="table-responsive mb-5">
                            <table class="table mb-0 crm-datatable" data-export-name="partners-merchants">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ __('User') }}</th>
                                        <th>{{ __('Email') }}</th>
                                        <th>{{ __('Partnership status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($partnerMerchants as $u)
                                        <tr>
                                            <td class="fw-medium">{{ $u->name }}</td>
                                            <td><a href="mailto:{{ $u->email }}">{{ $u->email }}</a></td>
                                            <td><span class="badge bg-success-subtle text-success">{{ __(data_get($u->pivot, 'status', 'accepted')) }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if (!$list || $list->count() === 0)
                        <p class="text-muted text-center py-5 mb-0">
                            @if(!empty($shareInvitation))
                                {{ __('No email invitations yet. Use the shared link above or send by email.') }}
                            @else
                                {{ __('No invitations yet.') }}
                            @endif
                        </p>
                    @else
                        @can('invitations-list')
                            <form id="invitations-bulk-form" action="{{ route('invitations.bulk-destroy') }}" method="POST" class="d-none">
                                @csrf
                                <div id="invitations-bulk-ids"></div>
                            </form>
                        @endcan
                        <div class="table-responsive">
                            <table class="table mb-0 crm-datatable" data-export-name="invitations" id="kt_table_invitations" data-dt-hide-buttons-ui="1">
                                <thead class="table-light">
                                    <tr>
                                        @can('invitations-list')
                                            <th style="width: 16px;" class="no-export no-sort">
                                                <div class="form-check mb-0">
                                                    <input type="checkbox" class="form-check-input" name="select-all" id="invitations-select-all">
                                                </div>
                                            </th>
                                        @endcan
                                        <th class="ps-0">{{ __('Email') }}</th>
                                        @if(auth()->user()->hasRole('Admin'))
                                            <th>{{ __('From') }}</th>
                                        @endif
                                        <th>{{ __('Role / invite as') }}</th>
                                        <th>{{ __('Invite code') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Expires') }}</th>
                                        <th class="no-export">{{ __('Link') }}</th>
                                        <th class="text-end no-export no-sort">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($list as $invitation)
                                        @php
                                            $roleLabel = $invitation->invited_role === 'supplier' ? __('Supplier') : ($invitation->invited_role === 'merchant' ? __('Merchant') : '—');
                                            $legacyPerms = null;
                                            if (!empty($invitation->permissions)) {
                                                $legacyPerms = @unserialize($invitation->permissions);
                                            }
                                            $canDeleteInvitation = ($invitation->is_reusable || ! $invitation->registered_at) && (auth()->user()->hasRole('Admin') || (int)$invitation->sender_user_id === (int)auth()->id());
                                        @endphp
                                        <tr>
                                            @can('invitations-list')
                                                <td style="width: 16px;" class="no-export">
                                                    @if($canDeleteInvitation)
                                                        <div class="form-check">
                                                            <input type="checkbox" class="form-check-input invitations-checkbox" name="check" value="{{ $invitation->id }}">
                                                        </div>
                                                    @endif
                                                </td>
                                            @endcan
                                            <td class="ps-0 py-3">
                                                @if($invitation->is_reusable)
                                                    <span class="text-muted">{{ __('Open shared link') }}</span>
                                                @else
                                                    <a href="mailto:{{ $invitation->email }}">{{ $invitation->email }}</a>
                                                @endif
                                            </td>
                                            @if(auth()->user()->hasRole('Admin'))
                                                <td class="py-3">{{ $invitation->sender?->name ?? '—' }}</td>
                                            @endif
                                            <td class="py-3">
                                                {{ $roleLabel }}
                                                @if(is_array($legacyPerms) && count($legacyPerms))
                                                    <br><small class="text-muted">{{ __('Legacy permissions') }}</small>
                                                @endif
                                            </td>
                                            <td class="py-3"><kbd class="fs-7">{{ $invitation->invite_code ?? '—' }}</kbd></td>
                                            <td class="py-3">
                                                @if($invitation->is_reusable)
                                                    <span class="badge bg-primary-subtle text-primary">{{ __('Reusable') }}</span>
                                                @elseif($invitation->registered_at)
                                                    <span class="badge bg-success-subtle text-success">{{ __('accepted') }}</span>
                                                @elseif($invitation->isExpired())
                                                    <span class="badge bg-danger-subtle text-danger">{{ __('Expired') }}</span>
                                                @else
                                                    <span class="badge bg-warning-subtle text-warning">{{ __($invitation->status ?? 'pending') }}</span>
                                                @endif
                                            </td>
                                            <td class="py-3">{{ $invitation->expires_at ? $invitation->expires_at->format('d/m/Y') : '—' }}</td>
                                            <td class="no-export py-3" style="max-width:200px;word-break:break-all;"><small><kbd class="fs-8">{{ $invitation->getLink() }}</kbd></small></td>
                                            <td class="text-end no-export py-3">
                                                @if($canDeleteInvitation)
                                                    <div class="d-inline-flex align-items-center gap-1 justify-content-end">
                                                        <form action="{{ route('invitations.destroy', $invitation) }}" method="POST" class="d-inline" data-rizz-confirm="{{ __('Are you sure?') }}">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="list-action-btn bg-danger-subtle text-danger" title="{{ __('Delete') }}"><i class="las la-trash-alt fs-18"></i></button>
                                                        </form>
                                                    </div>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <x-slot name="script">
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function shareInviteFlashCopied(btn) {
                if (!btn) return;
                var labels = btn.querySelectorAll('.share-invite-copy-label');
                var copied = @json(__('Copied!'));
                labels.forEach(function (span) {
                    var prev = span.textContent;
                    span.textContent = copied;
                    span.setAttribute('data-prev-label', prev);
                });
                btn.classList.add('btn-success');
                btn.classList.remove('btn-outline-primary');
                setTimeout(function () {
                    labels.forEach(function (span) {
                        var p = span.getAttribute('data-prev-label');
                        if (p) span.textContent = p;
                        span.removeAttribute('data-prev-label');
                    });
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-outline-primary');
                }, 1600);
            }
            document.getElementById('share-invite-copy-link')?.addEventListener('click', function() {
                var id = this.getAttribute('data-copy-target');
                var el = id ? document.getElementById(id) : null;
                var text = el && ('value' in el) ? el.value : '';
                if (text) {
                    navigator.clipboard.writeText(text).then(function() { shareInviteFlashCopied(document.getElementById('share-invite-copy-link')); });
                }
            });
            document.getElementById('share-invite-copy-code')?.addEventListener('click', function() {
                var t = this.getAttribute('data-copy-text');
                if (t) {
                    navigator.clipboard.writeText(t).then(function() { shareInviteFlashCopied(document.getElementById('share-invite-copy-code')); });
                }
            });
            var tableEl = document.getElementById('kt_table_invitations');
            var excelBtn = document.getElementById('crm-invitations-excel-btn');
            if (tableEl && excelBtn && typeof jQuery !== 'undefined') {
                function bindExcel() {
                    if (!jQuery.fn.DataTable || !jQuery.fn.DataTable.isDataTable(tableEl)) {
                        setTimeout(bindExcel, 50);
                        return;
                    }
                    var api = jQuery(tableEl).DataTable();
                    excelBtn.addEventListener('click', function() {
                        try { api.button('.buttons-excel').trigger(); } catch (e) {
                            var $h = jQuery(tableEl).closest('.dataTables_wrapper').find('.buttons-excel').first();
                            if ($h.length) $h.trigger('click');
                        }
                    });
                }
                bindExcel();
            }
        });
    </script>
    </x-slot>
</x-default-layout>
