<div id="page-loader" class="page-loader" aria-hidden="true">
    <div class="page-loader__inner">
        <img src="{{ asset('icon.png') }}" alt="{{ config('app.name') }}" class="page-loader__logo">
    </div>
</div>
<style>
.page-loader{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:#fff;transition:opacity .4s ease,visibility .4s ease}
.page-loader.page-loader--hidden{opacity:0;visibility:hidden;pointer-events:none}
.page-loader__logo{width:72px;height:72px;object-fit:contain;animation:page-loader-pulse 1.2s ease-in-out infinite}
@keyframes page-loader-pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.85;transform:scale(1.05)}}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){var e=document.getElementById('page-loader');if(e){function t(){e.classList.add('page-loader--hidden');setTimeout(function(){e.remove()},450)}document.readyState==='complete'?t():window.addEventListener('load',t),setTimeout(t,3e3)}});
</script>
