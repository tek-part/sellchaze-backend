<nav class="mod-anav">
    <a href="/account" class="{{ $active === 'dashboard' ? 'is-active' : '' }}">🏠 لوحة الحساب</a>
    <a href="/account/orders" class="{{ in_array($active, ['orders', 'order-details']) ? 'is-active' : '' }}">📦 طلباتي</a>
    <a href="/wishlist">❤ قائمة الرغبات</a>
    <a href="/account/addresses" class="{{ $active === 'addresses' ? 'is-active' : '' }}">📍 العناوين</a>
    <a href="/account/reviews" class="{{ $active === 'reviews' ? 'is-active' : '' }}">⭐ تقييماتي</a>
    <a href="/account/profile" class="{{ $active === 'profile' ? 'is-active' : '' }}">👤 الملف الشخصي</a>
    <a href="/account/settings" class="{{ $active === 'settings' ? 'is-active' : '' }}">⚙ الإعدادات</a>
    <a href="/account/login" style="color:var(--danger)">↩ تسجيل الخروج</a>
</nav>
