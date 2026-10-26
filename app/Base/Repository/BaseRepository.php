<?php

namespace App\Base\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class BaseRepository {

    protected $model;

    /**
     * @param $obj
     * @return void
     */
    public function setModel($obj): void {
        $this->model = resolve($obj);
    }

    /**
     * @param string|null $order_by
     * @return mixed
     */
    public function all(?string $order_by = 'id'): mixed {
        return $this->model->orderBy($order_by)->orderBy('id')->get();
    }

    /**
     * Ids inválidos (`abc`, `-1`) também respondem "não encontrado".
     *
     * @param mixed $id
     * @param array $with
     * @param string $message_key
     * @return Model
     */
    public function find(
        mixed  $id,
        array  $with = [],
        string $message_key = 'messages.not_found'
    ): Model {
        $model = parse_id($id) ? $this->model->with($with)->find(parse_id($id)) : null;

        if (!$model) {
            throw new ModelNotFoundException($message_key);
        }

        return $model;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return Model
     */
    public function save(array $attributes): Model {
        return $this->model->create($attributes)->fresh();
    }

    /**
     * @param mixed $id
     * @param array<string, mixed> $attributes
     * @param string $message_key
     * @return Model
     */
    public function update(mixed $id, array $attributes, string $message_key = 'messages.not_found'): Model {
        return tap($this->find($id, message_key: $message_key), function (Model $model) use ($attributes) {
            $model->update($attributes);
        })->fresh();
    }

    /**
     * @param mixed $id
     * @param string $message_key
     * @return bool
     */
    public function delete(mixed $id, string $message_key = 'messages.not_found'): bool {
        return (bool)$this->find($id, message_key: $message_key)->delete();
    }
}
