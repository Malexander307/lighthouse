<?php

namespace Nuwave\Lighthouse\Execution\Arguments;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nuwave\Lighthouse\Support\Contracts\ArgResolver;

class SaveModel implements ArgResolver
{
    /**
     * @var \Illuminate\Database\Eloquent\Relations\Relation|null
     */
    protected $parentRelation;

    public function __construct(?Relation $parentRelation = null)
    {
        $this->parentRelation = $parentRelation;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  \Nuwave\Lighthouse\Execution\Arguments\ArgumentSet  $args
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function __invoke($model, $args)
    {
        // Extract $morphTo first, as MorphTo extends BelongsTo
        [$morphTo, $remaining] = ArgPartitioner::relationMethods(
            $args,
            $model,
            MorphTo::class
        );

        [$belongsTo, $remaining] = ArgPartitioner::relationMethods(
            $remaining,
            $model,
            BelongsTo::class
        );

        // Use all the remaining attributes and fill the model
        $model->fill($remaining->toArray());

        foreach ($belongsTo->arguments as $relationName => $nestedOperations) {
            $belongsToResolver = new ResolveNested(new NestedBelongsTo($relationName));
            $belongsToResolver($model, $nestedOperations->value);
        }

        foreach ($morphTo->arguments as $relationName => $nestedOperations) {
            $morphToResolver = new ResolveNested(new NestedMorphTo($relationName));
            $morphToResolver($model, $nestedOperations->value);
        }

        if ($this->parentRelation instanceof HasOneOrMany) {
            // If we are already resolving a nested create, we might
            // already have an instance of the parent relation available.
            // In that case, use it to set the current model as a child.
            $this->parentRelation->save($model);

            return $model;
        }

        $model->save();

        if ($this->parentRelation instanceof BelongsTo) {
            $this->parentRelation
                ->associate($model)
                ->save();
        }

        if ($this->parentRelation instanceof BelongsToMany) {
            $this->parentRelation->syncWithoutDetaching($model);
        }

        return $model;
    }
}
