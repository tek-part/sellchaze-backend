<?php
use App\Core\Theme;
use App\Models\User;
use App\Models\Profile;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Invitation;
use App\Models\OrderSuppliers;

if (!function_exists('theme')) {
    function theme()
    {
        return app(Theme::class);
    }
}

if (!function_exists('addHtmlAttribute')) {
    /**
     * Add HTML attributes by scope
     *
     * @param $scope
     * @param $name
     * @param $value
     *
     * @return void
     */
    function addHtmlAttribute($scope, $name, $value)
    {
        theme()->addHtmlAttribute($scope, $name, $value);
    }
}


if (!function_exists('addHtmlAttributes')) {
    /**
     * Add multiple HTML attributes by scope
     *
     * @param $scope
     * @param $attributes
     *
     * @return void
     */
    function addHtmlAttributes($scope, $attributes)
    {
        theme()->addHtmlAttributes($scope, $attributes);
    }
}


if (!function_exists('addHtmlClass')) {
    /**
     * Add HTML class by scope
     *
     * @param $scope
     * @param $value
     *
     * @return void
     */
    function addHtmlClass($scope, $value)
    {
        theme()->addHtmlClass($scope, $value);
    }
}


if (!function_exists('printHtmlAttributes')) {
    /**
     * Print HTML attributes for the HTML template
     *
     * @param $scope
     *
     * @return string
     */
    function printHtmlAttributes($scope)
    {
        return theme()->printHtmlAttributes($scope);
    }
}


if (!function_exists('printHtmlClasses')) {
    /**
     * Print HTML classes for the HTML template
     *
     * @param $scope
     * @param $full
     *
     * @return string
     */
    function printHtmlClasses($scope, $full = true)
    {
        return theme()->printHtmlClasses($scope, $full);
    }
}


if (!function_exists('getSvgIcon')) {
    /**
     * Get SVG icon content. When using Rizz theme, returns Iconoir icon classes.
     *
     * @param $path
     * @param $classNames
     * @param $folder
     *
     * @return string
     */
    function getSvgIcon($path, $classNames = 'svg-icon', $folder = 'assets/media/icons/')
    {
        if (config('settings.KT_THEME') === 'rizz') {
            $iconMap = [
                'duotune/general/gen025.svg' => 'iconoir-home-simple',
                'duotune/general/gen051.svg' => 'iconoir-user',
                'duotune/general/gen055.svg' => 'iconoir-multiple-pages',
                'duotune/general/gen032.svg' => 'iconoir-activity',
                'duotune/general/gen022.svg' => 'iconoir-bell',
                'duotune/general/gen002.svg' => 'iconoir-search',
                'duotune/general/gen005.svg' => 'iconoir-world',
                'duotune/general/gen019.svg' => 'iconoir-settings',
                'duotune/general/gen021.svg' => 'iconoir-search',
                'duotune/general/gen041.svg' => 'iconoir-plus',
                'duotune/general/gen042.svg' => 'iconoir-minus',
                'duotune/general/gen014.svg' => 'iconoir-cursor-pointer',
                'duotune/ecommerce/ecm004.svg' => 'iconoir-delivery-truck',
                'duotune/ecommerce/ecm001.svg' => 'iconoir-cart',
                'duotune/ecommerce/ecm002.svg' => 'iconoir-shop',
                'duotune/ecommerce/ecm009.svg' => 'iconoir-shop',
                'duotune/communication/com005.svg' => 'iconoir-document',
                'duotune/communication/com006.svg' => 'iconoir-send',
                'duotune/communication/com010.svg' => 'iconoir-mail',
                'duotune/communication/com011.svg' => 'iconoir-bell',
                'duotune/communication/com012.svg' => 'iconoir-message',
                'duotune/communication/com004.svg' => 'iconoir-mail',
                'duotune/finance/fin001.svg' => 'iconoir-wallet',
                'duotune/finance/fin002.svg' => 'iconoir-hand-card',
                'duotune/finance/fin006.svg' => 'iconoir-credit-card',
                'duotune/finance/fin009.svg' => 'iconoir-wallet',
                'duotune/files/fil010.svg' => 'iconoir-folder',
                'duotune/files/fil023.svg' => 'iconoir-cloud',
                'duotune/files/fil024.svg' => 'iconoir-folder',
                'duotune/files/fil025.svg' => 'iconoir-document',
                'duotune/coding/cod003.svg' => 'iconoir-lock',
                'duotune/arrows/arr061.svg' => 'iconoir-arrow-left',
                'duotune/arrows/arr064.svg' => 'iconoir-arrow-right',
                'duotune/arrows/arr066.svg' => 'iconoir-arrow-up',
                'duotune/arrows/arr079.svg' => 'iconoir-nav-arrow-right',
                'duotune/abstract/abs015.svg' => 'iconoir-menu-scale',
                'duotune/abstract/abs042.svg' => 'iconoir-tag',
                'duotune/abstract/abs043.svg' => 'iconoir-settings',
                'duotune/abstract/abs045.svg' => 'iconoir-chart-bubble',
                'duotune/art/art002.svg' => 'iconoir-paint-brush',
                'duotune/graphs/gra001.svg' => 'iconoir-graph-up',
                'duotune/graphs/gra006.svg' => 'iconoir-pie-chart',
                'duotune/text/txt001.svg' => 'iconoir-menu-scale',
            ];
            if (isset($iconMap[$path])) {
                $iconClass = $iconMap[$path];
                $sizeClass = str_contains($classNames, '2hx') ? ' fs-2' : (str_contains($classNames, '3x') ? ' fs-3' : (str_contains($classNames, '3hx') ? ' fs-3' : ''));
                return '<i class="' . $iconClass . ' ' . $classNames . $sizeClass . '"></i>';
            }
            return '<i class="iconoir-more-horiz ' . $classNames . '"></i>';
        }
        return theme()->getSvgIcon($path, $classNames, $folder);
    }
}

if (!function_exists('formatNumber')) {
    /**
     * Format a number with thousands separator.
     * Ensures 0 displays as "0".
     *
     * @param int|float|null $number
     * @param int $decimals
     * @return string
     */
    function formatNumber($number, $decimals = 0)
    {
        $number = $number ?? 0;
        return number_format((float) $number, $decimals, '.', ',');
    }
}

if (!function_exists('includeFavicon')) {
    /**
     * Include favicon from settings
     *
     * @return string
     */
    function includeFavicon()
    {
        return theme()->includeFavicon();
    }
}


if (!function_exists('includeFonts')) {
    /**
     * Include the fonts from settings
     *
     * @return string
     */
    function includeFonts()
    {
        return theme()->includeFonts();
    }
}


if (!function_exists('getGlobalAssets')) {
    /**
     * Get the global assets
     *
     * @param $type
     *
     * @return array
     */
    function getGlobalAssets($type = 'js')
    {
        return theme()->getGlobalAssets($type);
    }
}


if (!function_exists('addVendors')) {
    /**
     * Add multiple vendors to the page by name. Refer to settings KT_THEME_VENDORS
     *
     * @param $vendors
     *
     * @return void
     */
    function addVendors($vendors)
    {
        theme()->addVendors($vendors);
    }
}


if (!function_exists('addVendor')) {
    /**
     * Add single vendor to the page by name. Refer to settings KT_THEME_VENDORS
     *
     * @param $vendor
     *
     * @return void
     */
    function addVendor($vendor)
    {
        theme()->addVendor($vendor);
    }
}


if (!function_exists('addJavascriptFile')) {
    /**
     * Add custom javascript file to the page
     *
     * @param $file
     *
     * @return void
     */
    function addJavascriptFile($file)
    {
        theme()->addJavascriptFile($file);
    }
}


if (!function_exists('addCssFile')) {
    /**
     * Add custom CSS file to the page
     *
     * @param $file
     *
     * @return void
     */
    function addCssFile($file)
    {
        theme()->addCssFile($file);
    }
}


if (!function_exists('getVendors')) {
    /**
     * Get vendor files from settings. Refer to settings KT_THEME_VENDORS
     *
     * @param $type
     *
     * @return array
     */
    function getVendors($type)
    {
        return theme()->getVendors($type);
    }
}


if (!function_exists('getCustomJs')) {
    /**
     * Get custom js files from the settings
     *
     * @return array
     */
    function getCustomJs()
    {
        return theme()->getCustomJs();
    }
}


if (!function_exists('getCustomCss')) {
    /**
     * Get custom css files from the settings
     *
     * @return array
     */
    function getCustomCss()
    {
        return theme()->getCustomCss();
    }
}


if (!function_exists('getHtmlAttribute')) {
    /**
     * Get HTML attribute based on the scope
     *
     * @param $scope
     * @param $attribute
     *
     * @return array
     */
    function getHtmlAttribute($scope, $attribute)
    {
        return theme()->getHtmlAttribute($scope, $attribute);
    }
}

if (!function_exists('isUrl')) {
    /**
     * Get HTML attribute based on the scope
     *
     * @param $url
     *
     * @return mixed
     */
    function isUrl($url)
    {
        return filter_var($url, FILTER_VALIDATE_URL);
    }
}

if (!function_exists('image')) {
    /**
     * Get image url by path
     *
     * @param $path
     *
     * @return string
     */
    function image($path)
    {
        $base = config('settings.KT_THEME') === 'rizz' ? 'rizz/media/' : 'assets/media/';
        return asset($base.$path);
    }
}

if (!function_exists('placeholder_image')) {
    /**
     * Get placeholder image URL by type (avatar, product, order).
     */
    function placeholder_image(string $type = 'avatar'): string
    {
        $path = match ($type) {
            'product' => 'placeholders/product-default.svg',
            'order' => 'placeholders/order-default.svg',
            default => 'avatars/blank.png',
        };
        return image($path);
    }
}

if (!function_exists('product_image')) {
    /**
     * Get product image URL or placeholder if empty.
     */
    function product_image($product, string $size = 'thumbnails'): string
    {
        if (!$product || empty($product->image)) {
            return placeholder_image('product');
        }
        $img = $product->image;
        if (str_starts_with($img, 'http://') || str_starts_with($img, 'https://')) {
            return $img;
        }
        return asset('storage/uploads/products/'.$size.'/'.$img);
    }
}

if (!function_exists('order_image')) {
    /**
     * Get order image URL or placeholder if empty.
     */
    function order_image($order, string $size = 'thumbnails'): string
    {
        if (!$order || empty($order->image)) {
            return placeholder_image('order');
        }
        $img = $order->image;
        if (str_starts_with($img, 'http://') || str_starts_with($img, 'https://')) {
            return $img;
        }
        return asset('storage/uploads/orders/'.$size.'/'.$img);
    }
}

if (!function_exists('user_photo')) {
    /**
     * Get user photo URL (profile photo, Google avatar, or placeholder).
     *
     * @param int|null $user_id
     * @param string $size thumbnails|original
     * @param bool $tag return img tag
     * @param string|null $attrs HTML attributes for img tag
     * @param bool $avatar
     * @return string
     */
    function user_photo($user_id = null, $size = 'thumbnails', $tag = false, $attrs = null, $avatar = false)
    {
        $user = $user_id !== null
            ? User::where('id', $user_id)->first()
            : Auth::user();

        $photo = null;
        $isGoogleUrl = false;

        if ($user) {
            $profile = $user->profile;
            if ($profile && !empty($profile->photo)) {
                $photo = $profile->photo;
            } elseif (!empty($user->avatar)) {
                $photo = $user->avatar;
                $isGoogleUrl = true;
            }
        }

        if ($photo) {
            $src = $isGoogleUrl ? $photo : asset("storage/uploads/users/$size/$photo");
        } else {
            $src = placeholder_image('avatar');
        }

        if ($tag == true) {
            return '<img src="'.$src.'" alt="" '.$attrs.' />';
        }

        return $src;
    }
}

if (!function_exists('generateProfle')) {
    /**
     * Check if profile record doesn't exit next creates new profile
     *
     * @param  \Illuminate\Http\Request  $request
     * @param $user_id
     * 
     * @return \Illuminate\Http\Response
     */
    function generateProfle(Request $request, $user_id = null, $flag = false)
    {
        if($user_id) {
            $user       = User::find($user_id);
        }
        else {
            $user_id    = Auth::user()->id;
            $user       = Auth::user();
        }

        $profile = Profile::where('user_id', $user_id)->first();
        
        if ($profile === null) {
            $profile                = new Profile;
            $profile->username      = generateUserName($user->email);
            $profile->biography     = $request->biography;
            $profile->company       = $request->company;
            $profile->country       = $request->country;
            $profile->city          = $request->city;
            $profile->address       = $request->address;
            $profile->gender        = !empty($request->gender) ? $request->gender : 'male';
            $profile->phone         = $request->phone;
            $profile->whatsapp      = $request->whatsapp;
            $profile->birthdate     = $request->birthdate;
            $profile->social_media  = !empty($request->social_media) ? serialize($request->social_media) : null;
            $profile->products      = !empty($request->products) ? serialize($request->products) : null;
            $profile->active        = ($request->active != null) ? $request->active : 0;
            $profile->private       = ($request->private != null) ? $request->private : 0;
            $profile->user_id       = $user_id;
            $profile->created_at    = date("Y-m-d H:i:s");
            $profile->updated_at    = date("Y-m-d H:i:s");
        }
        else {
            $profile->username      = !empty($request->username) ? $request->username : generateUserName($user->email, true);
            $profile->biography     = !empty($request->biography) ? $request->biography : $profile->biography;
            $profile->company       = !empty($request->company) ? $request->company : $profile->company;
            $profile->country       = !empty($request->country) ? $request->country : $profile->country;
            $profile->city          = !empty($request->city) ? $request->city : $profile->city;
            $profile->address       = !empty($request->address) ? $request->address : $profile->address;
            $profile->gender        = !empty($request->gender) ? $request->gender : $profile->gender;
            $profile->phone         = !empty($request->phone) ? $request->phone : $profile->phone;
            $profile->whatsapp      = !empty($request->whatsapp) ? $request->whatsapp : $profile->whatsapp;
            $profile->birthdate     = !empty($request->birthdate) ? $request->birthdate : $profile->birthdate;
            $profile->social_media  = !empty($request->social_media) ? serialize($request->social_media) : $profile->social_media;
            $profile->products      = !empty($request->products) ? serialize($request->products) : null;
            $profile->active        = $request->active;
            $profile->private       = $request->private;
            $profile->user_id       = $user_id;
            $profile->updated_at    = date("Y-m-d H:i:s");
        }

        if ($request->hasFile('photo')) {
            if($profile->photo) {
                $image_path = public_path("storage/uploads/users/original/") .$profile->photo;
                $thumb_path = public_path("storage/uploads/users/thumbnails/") .$profile->photo;

                if(File::exists($image_path)) {
                    File::delete($image_path);
                }
                
                if(File::exists($thumb_path)) {
                    File::delete($thumb_path);
                }
            }

            $photo              = $request->file('photo');
            $input['photo']     = md5($photo->getClientOriginalName() . time()) . "." . $photo->getClientOriginalExtension();
            $destinationPath    = public_path('/storage/uploads/users/thumbnails');
            $imgFile            = Image::make($photo->getRealPath());

            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            $imgFile->resize(300, 300, function ($constraint) {
                $constraint->aspectRatio();
            })->save($destinationPath.'/'.$input['photo']);

            $destinationPath = public_path('/storage/uploads/users/original');

            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            $photo->move($destinationPath, $input['photo']);

            $profile->photo = $input['photo'];
        }
        
        $profile->save();
    }
}

if (!function_exists('random_alphanumeric')) {
    /**
     * Generate random alpha-numeric string
     *
     * @param $length
     *
     * @return string
     */
    function random_alphanumeric(int $length): string
    {
        $result = '';

        do
        {
            //Base 64 produces 4 characters for each 3 bytes, so most times this will give enough bytes in a single pass
            $bytes = random_bytes(($length + 3 - strlen($result)) * 2);

            //Discard non-alhpanumeric characters
            $result .= str_replace(['/','+','='],['','',''],base64_encode($bytes));

            //Keep adding characters until the string is long enough
            //Add a few extra because the last 2 or 3 characters of a base 64 string tend to be less diverse
        }while(strlen($result) < $length + 3);

        return substr($result, 0, $length);
    }
}

if (!function_exists('generateUserName')) {
    /**
     * Generate username from passed value
     *
     * @param $username
     *
     * @return string
     */
    function generateUserName($username, $flag = false){
        if(filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $username = Str::lower(Str::slug(substr($username, 0, strrpos($username, '@'))));
        }

        if(Profile::where('username', '=', $username)->exists() && $flag == false){
            $uniqueUserName = $username.'-'.Str::lower(Str::random(4));
            $username = generateUserName($uniqueUserName);
        }

        return $username;
    }
}

if (!function_exists('countOrders')) {
    /**
     * Return count of order according to passed type
     *
     * @param $type
     *
     * @return string
     * @phpstan-return int
     */
    function countOrders($type = null){
        $user_id = Auth::user()->id;

        if($type == null) {
            $orders = Order::count();
        }
        else {
            /*$orders = Order::whereHas('suppliers', function ($q) use($type, $user_id) {
                                                        $q->where($type, $user_id);
                                                    })->count();*/


            $orders = Order::whereHas('suppliers', function ($query) use($type, $user_id) {
                            $query->where($type, $user_id);
                        })->whereDoesntHave('quotations', function ($query) use($user_id){
                            $query->where('supplier_user_id', $user_id);
                        })->whereDoesntHave('quotations', function ($query) {
                            $query->where('status', 'accepted');
                        })->count();
        }

        return $orders;
   }
}

if (!function_exists('OrderSupplierCheck')) {
    /**
     * Check if user is the supplier of order
     *
     * @param $order_id
     * @param $user_id
     *
     * @return integer
     * @phpstan-return bool
     */
    function OrderSupplierCheck($order_id, $user_id = null){
        if($user_id == null)
            $user_id = Auth::user()->id;
  
        $order_supplier = OrderSuppliers::where('order_id', $order_id)->where('supplier', $user_id)->first();

        if($order_supplier) {
            return true;
        }
        
        return false;
    }
}

if (!function_exists('getUserInfo')) {
    /**
     * Check if user is the supplier of order
     *
     * @param $order_id
     * @param $user_id
     *
     * @return integer
     * @phpstan-return \App\Models\User|\Illuminate\Database\Eloquent\Collection<int, \App\Models\User>|false
     */
    function getUserInfo($user_id = null){
        if($user_id == null)
            $user_id = Auth::user()->id;

        $user = User::find($user_id);

        if($user) {
            return $user;
        }
        
        return false;
    }
}

if (!function_exists('invited')) {
    /**
     * Check if user is invited by other user or not
     * Return either invitation information or (user id param OR logged current logged user id if user id isn't passed)
     * If the 2nd param is passed to true then invitation send user id will be returned if invitation exists 
     * OR (user id param OR logged current logged user id if user id isn't passed)
     *
     * @param $user_id
     *
     * @return integer
     * @phpstan-return mixed
     */
    function invited($user_id = null, $return_id = false){
        if($user_id == null)
            $user_id = Auth::user()->id; // 1

        $invitation = Invitation::where('receiver_user_id', Auth::user()->id)->first();

        $user = User::find($user_id);

        if($invitation) {
            if($return_id == false)
                return $invitation;
            else
                return $invitation->sender_user_id;
        }
        
        if($return_id == false)
            return false;
        else
            return $user_id;
    }
}

if (!function_exists('b2bListingsUserId')) {
    /**
     * User id for filtering orders, quotations, deals, and balance lists.
     * Merchants and suppliers must use their own account id — not invitation.sender_user_id,
     * or invited merchants see the inviter's context and miss synced store orders.
     */
    function b2bListingsUserId(): int
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        if (! $user) {
            return 0;
        }
        if ($user->hasRole('Employee') && ! empty($user->parent_user_id)) {
            return (int) $user->parent_user_id;
        }
        if ($user->hasRole('Merchant') || $user->hasRole('Supplier')) {
            return (int) $user->id;
        }

        return (int) invited(null, true);
    }
}

if (!function_exists('countries')) {
    /**
     * Get list of countries for dropdowns (uses Rinvex Countries).
     *
     * @return array<int, array{name: string}>
     */
    function countries(): array
    {
        $raw = \Rinvex\Country\CountryLoader::countries(false, false);
        $list = [];
        foreach ($raw as $item) {
            if (isset($item['name'])) {
                $list[] = ['name' => $item['name']];
            }
        }
        usort($list, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $list;
    }
}
