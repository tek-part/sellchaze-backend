{{-- Reusable table wrapper matching orders/out style --}}
<div class="table-responsive rounded border border-gray-300 border-dashed kt-table-wrap">
	<style>
		.kt-table-wrap .table { margin-bottom: 0; }
		.kt-table-wrap th.actions-col,
		.kt-table-wrap td.actions-col {
			position: sticky;
			right: 0;
			background: var(--bs-body-bg, #fff);
			white-space: nowrap;
			box-shadow: -4px 0 8px rgba(0,0,0,.06);
			z-index: 1;
		}
		.kt-table-wrap thead th.actions-col { background: var(--bs-light, #f1faff); }
		.kt-table-wrap tbody tr:hover td.actions-col { background: var(--bs-body-bg, #fff); }
		.kt-table-wrap .actions-btns { flex-wrap: nowrap; white-space: nowrap; }
		.kt-table-wrap .btn-icon { width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; }
		.kt-table-wrap .btn-icon i { font-size: 14px; }
	</style>
