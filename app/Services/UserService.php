<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Eloquent\UserRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->users->paginate($filters);
    }

    public function create(array $data): User
    {
        $roles = Arr::pull($data, 'roles', []);
        $data['password'] = Hash::make($data['password']);

        /** @var User $user */
        $user = $this->users->create($data);
        $user->syncRoles($roles);

        return $user->load('roles');
    }

    public function update(User $user, array $data): User
    {
        $roles = Arr::pull($data, 'roles', []);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        /** @var User $user */
        $user = $this->users->update($user, $data);
        $user->syncRoles($roles);

        return $user->load('roles');
    }

    public function delete(User $user): void
    {
        $this->users->delete($user);
    }
}
