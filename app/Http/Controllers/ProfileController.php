<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Profile;
use App\Models\Category;
use App\Models\Invitation;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Session, Image, Auth, Validator, Redirect, File;

class ProfileController extends Controller
{
    /**
     * Display the specified resource.
     *
     * @param  string  $username
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function show($username = null, $user_id = null) {
        if($username) {
            $profile = Profile::where('username', $username)->first();

            if($profile === null) {                
                if($user_id !== null) {
                    $user_id                = decrypt($user_id);
                    $user                   = User::find($user_id);
                    $profile                = new Profile;
                    $profile->username      = generateUserName($username);
                    $profile->gender        = "male";
                    $profile->active        = 1;
                    $profile->private       = 0;
                    $profile->online        = 0;
                    $profile->user_id       = $user_id;
                    $profile->created_at    = date("Y-m-d H:i:s");
                    $profile->updated_at    = date("Y-m-d H:i:s");
        
                    $profile->save();
                }
            }

            $user = User::find($profile->user_id);

            if (Auth::user()->hasRole('Admin') || Auth::user()->id == $profile->user_id)
                $type = 'update';
            else
                $type = 'read';
        }
        else {
            /** @var \App\Models\User $user */
            $user   = Auth::user();
            $type   = 'update';

            // Ensure user has a profile (create if missing)
            if (!$user->profile) {
                Profile::create([
                    'username'   => generateUserName($user->email),
                    'gender'     => 'male',
                    'active'     => 1,
                    'private'    => 0,
                    'online'     => 1,
                    'user_id'    => $user->id,
                ]);
                $user->load('profile');
            }
        }

        /** @var \App\Models\User $user */
        $permissions        = Permission::all();
        $userHasPermissions = $user->getAllPermissions()->pluck('id')->toArray();

        // Check if profile visitor is the invitation sender and profile owner received this invitation
        // If so then the sender will be able to view products and categories in receiver profile to update it
        $invitation = Invitation::where('sender_user_id', Auth::user()->id)->where('receiver_user_id', $user->id)->first();

        $profile = $user->profile;
        $selectedCategory = ($profile && !empty($profile->products)) ? (array_keys(unserialize($profile->products))[0] ?? '') : '';
        $selectedProducts = ($profile && !empty($profile->products)) ? unserialize($profile->products) : [];
        $socialMedias = ($profile && !empty($profile->social_media)) ? unserialize($profile->social_media) : [];

        return view("pages.profiles.show-$type")->with('title' , 'View Profile')
                                           ->with('breadcrumb' , 'Profile')
                                           ->with('user', $user)
                                           ->with('permissions', $permissions)
                                           ->with('userHasPermissions', $userHasPermissions)
                                           ->with('categories', Category::cachedAll())
                                           ->with('countries', countries())
                                           ->with('invitation', $invitation)
                                           ->with('selected_category', $selectedCategory)
                                           ->with('selected_products', $selectedProducts)
                                           ->with('social_medias', $socialMedias);
    }

    /**
     * Display the specified resource.
     *
     * @param   $username
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    /*public function show($username)
    {
        $user = User::whereHas('profile', function ($q) use($username) {
                    $q->where('username', $username);
                })->firstOrFail();

        return view('pages.profiles.show')->with('title' , 'Profile')
                                          ->with('breadcrumb' , 'Profile')
                                          ->with('user', $user)
                                          ->with('selected_category', !empty($user->profile->products) ? array_keys(unserialize($user->profile->products))[0] : '')
                                          ->with('selected_products', !empty($user->profile->products) ? unserialize($user->profile->products) : [])
                                          ->with('social_medias', !empty($user->profile->social_media) ? unserialize($user->profile->social_media) : []);
    }*/
     
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
	public function update(Request $request, $username = null) {

        if($username) {
            $profile    = Profile::where('username', $username)->first();
            $user       = User::find($profile->user_id);
        }
        else {
            /** @var \App\Models\User $user */
            $user       = Auth::user();
            $profile    = Profile::where('user_id', $user->id)->first();
        }
        /** @var \App\Models\User $user */

        $rules = array(
            'name'          => 'required|min:5|max:255|',
            'email'         => 'required|string|email|max:255|unique:users,email,'.$user->id,
            'username'      => 'max:255|required|unique:profiles,username,'.$profile->id,
            'biography'     => 'nullable',
            'photo'         => 'nullable|image|mimes:jpg,png,webp|max:2048',
            'company'       => 'nullable|max:255',
            'country'       => 'required',
            'city'          => 'nullable|min:5|max:255',
            'address'       => 'nullable|max:255|regex:/(^[-0-9A-Za-z.,\/ ]+$)/',
            'gender'        => 'required|in:male,female',
            'phone'         => 'nullable|numeric|min:11',
            'whatsapp'      => 'nullable|numeric|min:11',
            'birthdate'     => 'nullable|date_format:Y-m-d|before:13 years ago',
            'social_media'  => 'nullable',
            'products'      => 'nullable',
            'active'        => 'nullable|boolean',
            'private'       => 'nullable|boolean'
        );

        if($request->password) {
            $rules['password'] = 'min:6|regex:/^.*(?=.{3,})(?=.*[a-zA-Z])(?=.*[0-9])(?=.*[\d\x])(?=.*[!$#%]).*$/';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return Redirect::back()->withInput()->withErrors($validator);
        }

        $user->name     = $request->name;
        $user->email    = $request->email;

        if($request->password){
            $user->password = bcrypt($request->password);
        }
        
        if(!empty($request->permissions)) {
            $user->syncPermissions($request->permissions);
        }

        $user->updated_at = date("Y-m-d H:i:s");

        $user->save();

        generateProfle($request, $user->id); //Check if user has a profile record in profiles table .. If not then create a record else update record

        Session::flash('success', 'Profile successfully updated.');

		return redirect()->back();
	}

    /**
     * Generate or regenerate the authenticated user's API key.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateApiKey(Request $request)
    {
        $user = Auth::user();
        $key = $user->generateApiKey();
        return response()->json([
            'api_key' => $key,
            'message' => __('API key generated successfully.'),
        ]);
    }

    /**
     * Delete the specified resource in storage.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
	public function deletePhoto($user_id = null) {
        if($user_id) {
            $user = User::find($user_id);
        }
        else {
            $user = Auth::user();
        }

        $profile = Profile::where('user_id', $user->id)->first();

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

        $profile->photo = null;

        $profile->save();

        return response()->json(["code" => 200, "status" => "success", "message" => "Your photo was successfully deleted."]);
    }

    /**
     * Display categories list and products related to each cateogry and assign it to the user.
     *
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function getAssignProducts($user_id = null) {
        if (!Auth::user()->hasRole('Admin')) {
            abort(403, __('Unauthorized. Admin only.'));
        }
        if($user_id) {
            $user = User::find(decrypt($user_id));
           
            if(!$user)
                return Redirect::back();
        }
        else {
            return Redirect::back();
        }

        $selectedProducts = !empty($user->profile->products) ? unserialize($user->profile->products) : [];
        $selectedCategory = !empty($selectedProducts) ? (string)(array_keys($selectedProducts)[0] ?? '') : '';
        $assignedProductIds = [];
        foreach ($selectedProducts as $catId => $pids) {
            $assignedProductIds = array_merge($assignedProductIds, is_array($pids) ? $pids : []);
        }
        $assignedProducts = \App\Models\Product::whereIn('id', array_unique($assignedProductIds))->with('category')->get();

        return view("pages.profiles.assign-products")
            ->with('title', 'Assign products')
            ->with('breadcrumb', 'Assign products')
            ->with('user', $user)
            ->with('user_id_encrypted', $user_id)
            ->with('categories', Category::cachedAll())
            ->with('selected_products', $selectedProducts)
            ->with('selected_category', $selectedCategory)
            ->with('assigned_products', $assignedProducts);
    }

    /**
     * Save assigned products for a user profile.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     * @phpstan-return \Illuminate\Http\Response|\Illuminate\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function postAssignProducts(Request $request) {
        if (!Auth::user()->hasRole('Admin')) {
            abort(403, __('Unauthorized. Admin only.'));
        }
        $request->validate([
            'user_id' => 'required|string',
            'products' => 'nullable|array',
            'products.*' => 'nullable|array',
            'products.*.*' => 'nullable|integer|exists:products,id',
        ]);

        try {
            $user = User::find(decrypt($request->user_id));
        } catch (\Exception $e) {
            return Redirect::back()->with('error', __('Invalid user.'));
        }

        if (!$user) {
            return Redirect::back()->with('error', __('User not found.'));
        }

        $profile = Profile::where('user_id', $user->id)->first();
        if (!$profile) {
            $profile = new Profile;
            $profile->username = generateUserName($user->email);
            $profile->user_id = $user->id;
            $profile->gender = 'male';
            $profile->active = 1;
            $profile->private = 0;
            $profile->save();
        }

        $products = $request->products ?? [];
        $normalized = [];
        foreach ($products as $catId => $pids) {
            if (!empty($catId) && is_array($pids)) {
                $ids = array_filter(array_map('intval', $pids));
                if (!empty($ids)) {
                    $normalized[(string)$catId] = array_values($ids);
                }
            }
        }

        $profile->products = !empty($normalized) ? serialize($normalized) : null;
        $profile->save();

        Session::flash('success', __('Products assigned successfully.'));

        return redirect()->route('profile.get.assign.products', $request->user_id);
    }

    /**
     * Show the profile completion form for new users.
     */
    public function complete()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $profile = $user->profile;
        if (!$profile) {
            $profile = Profile::create([
                'username'   => generateUserName($user->email),
                'gender'     => 'male',
                'active'     => 0,
                'private'    => 0,
                'online'     => 0,
                'user_id'    => $user->id,
            ]);
        }
        return view('pages.profiles.complete')
            ->with('title', __('Complete your profile'))
            ->with('breadcrumb', __('Complete profile'))
            ->with('user', $user)
            ->with('countries', countries());
    }

    /**
     * Store the profile completion data.
     */
    public function completeStore(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $profile = Profile::where('user_id', $user->id)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'company' => 'required|string|max:255',
            'phone'   => 'required|string|max:50',
            'country' => 'required|string|max:255',
            'city'    => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return Redirect::back()->withInput()->withErrors($validator);
        }

        $profile->company  = $request->company;
        $profile->phone    = $request->phone;
        $profile->country  = $request->country;
        $profile->city     = $request->city;
        $profile->address  = $request->address;
        $profile->updated_at = now();
        $profile->save();

        Session::flash('success', __('Profile completed successfully.'));
        return redirect()->route('dashboard');
    }
}