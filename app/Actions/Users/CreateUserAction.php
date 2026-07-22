<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Models\Profile;
use Illuminate\Http\UploadedFile;
use Intervention\Image\Facades\Image;

class CreateUserAction
{
    /**
     * Create a user with profile from validated data.
     *
     * @param  array<string, mixed>  $data
     * @return User
     */
    public function __invoke(array $data): User
    {
        $user = new User;
        $user->name = $data['name'];
        $user->email = $data['email'];

        if (! empty($data['password'])) {
            $user->password = bcrypt($data['password']);
        }

        $user->save();

        $roleName = ($data['account_type'] ?? 'merchant') === 'supplier' ? 'Supplier' : 'Merchant';
        $user->syncRoles([$roleName]);
        $user->syncPermissions([]);

        $profile = new Profile;
        $profile->username = generateUserName($user->email);
        $profile->biography = $data['biography'] ?? null;
        $profile->company = $data['company'] ?? null;
        $profile->country = $data['country'] ?? null;
        $profile->city = $data['city'] ?? null;
        $profile->address = $data['address'] ?? null;
        $profile->gender = $data['gender'] ?? 'male';
        $profile->phone = $data['phone'] ?? null;
        $profile->whatsapp = $data['whatsapp'] ?? null;
        $profile->birthdate = $data['birthdate'] ?? null;
        $profile->social_media = ! empty($data['social_media']) ? serialize($data['social_media']) : null;
        $profile->products = ! empty($data['products']) ? serialize($data['products']) : null;
        $profile->active = $data['active'] ?? 0;
        $profile->private = $data['private'] ?? 0;
        $profile->user_id = $user->id;

        if (isset($data['photo']) && $data['photo'] instanceof UploadedFile) {
            $profile->photo = $this->handlePhotoUpload($data['photo']);
        }

        $profile->save();

        return $user;
    }

    /**
     * Handle photo upload and return stored filename.
     */
    protected function handlePhotoUpload(UploadedFile $photo): string
    {
        $filename = md5($photo->getClientOriginalName() . time()) . '.' . $photo->getClientOriginalExtension();
        $destinationPath = public_path('/storage/uploads/users/thumbnails');

        if (! file_exists($destinationPath)) {
            mkdir($destinationPath, 0755, true);
        }

        $imgFile = Image::make($photo->getRealPath());
        $imgFile->resize(300, 300, function ($constraint) {
            $constraint->aspectRatio();
        })->save($destinationPath . '/' . $filename);

        $destinationPath = public_path('/storage/uploads/users/original');
        if (! file_exists($destinationPath)) {
            mkdir($destinationPath, 0755, true);
        }

        $photo->move($destinationPath, $filename);

        return $filename;
    }
}
