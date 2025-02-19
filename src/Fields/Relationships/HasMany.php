<?php

declare(strict_types=1);

namespace MoonShine\Fields\Relationships;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use MoonShine\ActionButtons\ActionButton;
use MoonShine\Buttons\HasManyButton;
use MoonShine\Components\TableBuilder;
use MoonShine\Contracts\Fields\HasFields;
use MoonShine\Contracts\Fields\HasUpdateOnPreview;
use MoonShine\Contracts\MoonShineRenderable;
use MoonShine\Fields\Field;
use MoonShine\Fields\Fields;
use MoonShine\Resources\ModelResource;
use MoonShine\Support\Condition;
use MoonShine\Traits\HasResource;
use MoonShine\Traits\WithFields;
use Throwable;

/**
 * @extends ModelRelationField<\Illuminate\Database\Eloquent\Relations\HasMany>
 * @extends HasResource<ModelResource, ModelResource>
 */
class HasMany extends ModelRelationField implements HasFields
{
    use WithFields;

    protected string $view = 'moonshine::fields.relationships.has-many';

    protected bool $isGroup = true;

    protected bool $outsideComponent = true;

    protected int $limit = 15;

    protected Closure|bool $onlyLink = false;

    protected ?string $linkRelation = null;

    protected bool $isCreatable = false;

    protected bool $isSearchable = true;

    protected bool $isAsync = false;

    protected ?ActionButton $creatableButton = null;

    public function creatable(
        Closure|bool|null $condition = null,
        ?ActionButton $button = null,
    ): static {
        $this->isCreatable = Condition::boolean($condition, true);
        $this->creatableButton = $button;

        return $this;
    }

    public function isCreatable(): bool
    {
        return $this->isCreatable;
    }

    public function searchable(Closure|bool|null $condition = null): static
    {
        $this->isSearchable = Condition::boolean($condition, true);

        return $this;
    }

    public function isSearchable(): bool
    {
        return $this->isSearchable;
    }

    /**
     * @throws Throwable
     */
    public function createButton(): ?ActionButton
    {
        if (is_null($this->getRelatedModel()?->getKey())) {
            return null;
        }

        if (! $this->isCreatable()) {
            return null;
        }

        $button = HasManyButton::for($this, button: $this->creatableButton);

        return $button->isSee($this->getRelatedModel())
            ? $button
            : null;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function async(): static
    {
        $this->isAsync = true;

        return $this;
    }

    public function isAsync(): bool
    {
        return $this->isAsync;
    }

    public function onlyLink(?string $linkRelation = null, Closure|bool|null $condition = null): static
    {
        $this->linkRelation = $linkRelation;

        if (is_null($condition)) {
            $this->onlyLink = true;

            return $this;
        }

        $this->onlyLink = $condition;

        return $this;
    }

    public function isOnlyLink(): bool
    {
        if (is_callable($this->onlyLink) && is_null($this->toValue())) {
            return value($this->onlyLink, 0, $this);
        }

        if (is_callable($this->onlyLink)) {
            $count = $this->toValue() instanceof Collection
                ? $this->toValue()->count()
                : $this->toValue()->total();

            return value($this->onlyLink, $count, $this);
        }

        return $this->onlyLink;
    }

    /**
     * @throws Throwable
     */
    public function preparedFields(): Fields
    {
        if (! $this->hasFields()) {
            $fields = $this->getResource()->getIndexFields();

            $this->fields($fields->toArray());

            return $this->getFields();
        }

        return $this->getFields()
            ->onlyFields(withWrappers: true)
            ->indexFields();
    }

    /**
     * @throws Throwable
     */
    public function preparedClonedFields(): Fields
    {
        $fields = $this->preparedFields();

        return $this->hasFields()
            ? $fields->map(fn (Field $field): Field => (clone $field))
            //If there are no fields, then the resource fields always return new objects
            : $fields;
    }

    protected function linkPreview(): View|string
    {
        $casted = $this->getRelatedModel();

        $countItems = $this->toValue()->count();

        if (is_null($relationName = $this->linkRelation)) {
            $relationName = str_replace('-resource', '', (string) moonshineRequest()->getResourceUri());
        }

        return ActionButton::make(
            "($countItems)",
            $this->getResource()->indexPageUrl([
                '_parentId' => $relationName . '-' . $casted?->{$casted?->getKeyName()},
            ])
        )
            ->icon('heroicons.outline.eye')
            ->render();
    }

    /**
     * @throws Throwable
     */
    protected function tablePreview(): View|string
    {
        $items = $this->toValue();

        if (filled($items)) {
            $items = $items->take($this->getLimit());
        }

        if ($this->isRawMode()) {
            return $items
                ->map(fn (Model $item) => data_get($item, $this->getResourceColumn()))
                ->implode(';');
        }

        $resource = $this->getResource();

        return TableBuilder::make(items: $items)
            ->fields($this->getFieldsOnPreview())
            ->cast($resource->getModelCast())
            ->preview()
            ->simple()
            ->render();
    }

    /**
     * HasOne/HasMany mapper with updateOnPreview
     * TODO refactor in 3.0
     */
    private function getFieldsOnPreview(): Closure
    {
        return function () {
            $fields = $this->preparedClonedFields();

            // the onlyFields method is needed to exclude stack fields
            $fields->onlyFields()->each(function (Field $field): void {
                if (
                    $field instanceof HasUpdateOnPreview
                    && $field->isUpdateOnPreview()
                    && ! $field->hasUpdateOnPreviewCustomUrl()
                ) {
                    $field->setUpdateOnPreviewUrl(
                        moonshineRouter()->updateColumn(
                            $field->getResourceUriForUpdate(),
                            $field->getPageUriForUpdate(),
                            $this->getRelationName(),
                        )
                    );
                }

                $field->setParent($this);
            });

            return $fields->toArray();
        };
    }

    protected function linkValue(): MoonShineRenderable
    {
        if (is_null($relationName = $this->linkRelation)) {
            $relationName = str_replace('-resource', '', (string) moonshineRequest()->getResourceUri());
        }

        return
            ActionButton::make(
                __('moonshine::ui.show') . " ({$this->toValue()->total()})",
                $this->getResource()->indexPageUrl([
                    '_parentId' => $relationName . '-' . $this->getRelatedModel()?->getKey(),
                ])
            )->primary();
    }

    /**
     * @throws Throwable
     */
    protected function tableValue(): MoonShineRenderable
    {
        $resource = $this->getResource();

        $asyncUrl = moonshineRouter()->toRelation(
            'search-relations',
            resourceItem: $this->getRelatedModel()?->getKey(),
            relation: $this->getRelationName()
        );

        $parentId = $this->getRelatedModel()?->getKey();

        $redirectAfter = $this->isAsync()
            ? ''
            : moonshineRequest()
                ->getResource()
                ?->formPageUrl($parentId) ?? '';

        return TableBuilder::make(items: $this->toValue())
            ->async($asyncUrl)
            ->when(
                $this->isSearchable() && ! empty($this->getResource()->search()),
                fn (TableBuilder $table): TableBuilder => $table->searchable()
            )
            ->name($this->getRelationName())
            ->fields($this->getFieldsOnPreview())
            ->cast($resource->getModelCast())
            ->when(
                $this->isNowOnForm(),
                fn (TableBuilder $table): TableBuilder => $table->withNotFound()
            )
            ->when(
                ! is_null($resource->trAttributes()),
                fn (TableBuilder $table): TableBuilder => $table->trAttributes(
                    $resource->trAttributes()
                )
            )
            ->when(
                ! is_null($resource->tdAttributes()),
                fn (TableBuilder $table): TableBuilder => $table->tdAttributes(
                    $resource->tdAttributes()
                )
            )
            ->buttons([
                ...$resource->getIndexButtons(),
                $resource->getDetailButton(isAsync: $this->isAsync()),
                HasManyButton::for($this, update: true),
                $resource->getDeleteButton(
                    componentName: $this->getRelationName(),
                    redirectAfterDelete: $redirectAfter,
                    isAsync: $this->isAsync()
                ),
                $resource->getMassDeleteButton(
                    componentName: $this->getRelationName(),
                    redirectAfterDelete: $redirectAfter,
                    isAsync: $this->isAsync()
                ),
            ]);
    }

    protected function prepareFill(array $raw = [], mixed $casted = null): mixed
    {
        return null;
    }

    /**
     * @throws Throwable
     */
    protected function resolvePreview(): View|string
    {
        if (is_null($this->toValue())) {
            $casted = $this->getRelatedModel();

            $this->setValue($casted?->{$this->getRelationName()});
        }

        return $this->isOnlyLink() ? $this->linkPreview() : $this->tablePreview();
    }

    /**
     * @throws Throwable
     */
    protected function resolveValue(): MoonShineRenderable
    {
        if (is_null($this->toValue())) {
            $casted = $this->getRelatedModel();
            $relation = $casted?->{$this->getRelationName()}();
            $resource = $this->getResource();
            $resource->customBuilder($relation);

            $this->setValue($resource->paginate());
        }

        return $this->isOnlyLink() ? $this->linkValue() : $this->tableValue();
    }

    /**
     * @return array<string, mixed>
     * @throws Throwable
     */
    protected function viewData(): array
    {
        return [
            'table' => $this->resolveValue(),
        ];
    }

    protected function resolveOnApply(): ?Closure
    {
        return static fn ($item) => $item;
    }

    /**
     * @throws Throwable
     */
    protected function resolveAfterDestroy(mixed $data): mixed
    {
        $this->getResource()
            ->getFormFields()
            ->onlyFields()
            ->each(fn (Field $field): mixed => $field->resolveFill($data->toArray(), $data)->afterDestroy($data));

        return $data;
    }
}
