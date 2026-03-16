<?php

namespace App\Policies;

use App\Models\Address;
use App\Models\User;

class AddressPolicy
{
    /**
     * Users can only view their own addresses.
     */
    public function view(User $user, Address $address): bool
    {
        return $user->id === $address->user_id;
    }

    /**
     * Users can only update their own addresses.
     */
    public function update(User $user, Address $address): bool
    {
        return $user->id === $address->user_id;
    }

    /**
     * Users can only delete their own addresses.
     */
    public function delete(User $user, Address $address): bool
    {
        return $user->id === $address->user_id;
    }

    /**
     * Users can only set their own addresses as default.
     */
    public function setDefault(User $user, Address $address): bool
    {
        return $user->id === $address->user_id;
    }
}
