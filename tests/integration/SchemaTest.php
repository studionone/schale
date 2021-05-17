<?php

use Doctrine\Common\Annotations\AnnotationReader;
use Shale\Schema\Type\StringPrimitive;
use Shale\Schema\Type\NumberPrimitive;
use Shale\Schema\TypeRegistry;
use Shale\Schema\Engine;
use Shale\Interfaces\Schema\SchemaTypeInterface;
use PHPUnit\Framework\TestCase;
use Shale\Exception\Schema\LoadSchemaException;

// These are used in assertions for readability
const IS_REQUIRED = true;
const IS_OPTIONAL = false;
const QUOTE_VALUES = true;
const DO_NOT_QUOTE_VALUES = false;

class SchemaTest extends TestCase
{
    public $schemaEngine;

    public function setUp(): void
    {
        $stringPrimitive = new StringPrimitive();
        $numberPrimitive = new NumberPrimitive();

        $typeRegistry = new TypeRegistry($stringPrimitive, $numberPrimitive);
        $annotationReader = new AnnotationReader();
        $this->schemaEngine = new Engine($typeRegistry, $annotationReader);
    }

    /**
     * Test with the simple TagModel, which has two properties, both strings.
     */
    public function testTagModel()
    {
        $rootModelFqcn = 'Shale\\Test\\Support\\Mock\\Model\\TagModel';

        $this->schemaEngine->loadSchemaForModels([$rootModelFqcn]);
        $schemas = $this->schemaEngine->getAllModelSchemas();

        // Type registry should now have 3 types (including 2 primitives)
        $this->assertEquals(3, count($schemas));

        // The schema's "modelFqcn" should be the TagModel FQCN
        $schema = $schemas['tag'];
        $this->assertEquals($rootModelFqcn, $schema->getModelFqcn());

        // The schema should have exactly 2 properties
        $properties = $schema->getAllProperties();
        $this->assertEquals(2, count($properties));

        // There should be a property "id", with type "number"
        $this->assertSchemaHasPropertyWith(
            $schema, 'id', 'id', 'number');
        // There should be a property "name", with type "string"
        $this->assertSchemaHasPropertyWith(
            $schema, 'name', 'name', 'string');
    }

    /**
     * Test with the more complex ArticleModel.
     *
     * ArticleModel has three properties, including "tags" which is a
     * TypedCollection of TagModel objects, and "regionId" which is
     * optional.
     */
    public function testArticleModel()
    {
        $tagModelFqcn = 'Shale\\Test\\Support\\Mock\\Model\\TagModel';
        $articleModelFqcn = 'Shale\\Test\\Support\\Mock\\Model\\Module\\ArticleModel';
        $modelFqcns = [$articleModelFqcn, $tagModelFqcn];

        $this->schemaEngine->loadSchemaForModels($modelFqcns);
        $schemas = $this->schemaEngine->getAllModelSchemas();

        // Type registry should now have 4 types (including 2 primitives)
        $this->assertEquals(4, count($schemas));

        // One of the schemas should be named 'article_module'
        // Its modelFqcn should match the ArticleModule's FQCN
        $schema = $schemas['article_module'];
        $this->assertEquals($articleModelFqcn, $schema->getModelFqcn());

        // This schema should have exactly 3 properties
        $properties = $schema->getAllProperties();
        $this->assertEquals(3, count($properties));

        // There should be a property "id", with type "number"
        $this->assertSchemaHasPropertyWith(
            $schema, 'id', 'id', 'number');
        // There should be a property "regionId", with type "string"
        $this->assertSchemaHasPropertyWith(
            $schema, 'regionId', 'regionId', 'string', IS_OPTIONAL);

        // There should be a property "tags"
        // This property should be a "typed array" with an item type "tag"
        $this->assertSchemaHasTypedArrayProperty(
            $schema, 'tags', 'tags', 'tag');
        // The "tags" typed array's itemType should be the actual
        // registered "tag" type (made from the TagModel)
        $this->assertEquals(
            $schemas['tag'],
            $properties['tags']->getValueType()->getItemType());
        $this->assertEquals(
            $tagModelFqcn,
            $properties['tags']
                ->getValueType()
                ->getItemType()
                ->getModelFqcn());
    }

    /**
     * Test with PayloadModel.
     *
     * PayloadModel has a single property "modules", which is a
     * MixedCollection allowing any object type.
     */
    public function testPayloadModel()
    {
        $payloadModelFqcn = 'Shale\\Test\\Support\\Mock\\Model\\PayloadModel';
        $bannerModelFqcn = 'Shale\\Test\\Support\\Mock\\Model\\BannerModel';
        $modelFqcns = [
            $payloadModelFqcn,
            $bannerModelFqcn,
        ];

        $this->schemaEngine->loadSchemaForModels($modelFqcns);
        $schemas = $this->schemaEngine->getAllModelSchemas();

        // Type registry should now have 4 types (including 2 primitives)
        $this->assertEquals(4, count($schemas));

        // The schema should be named "payload"
        // Its modelFqcn should match the PayloadModule's FQCN
        $schema = $schemas['payload'];
        $this->assertEquals($payloadModelFqcn, $schema->getModelFqcn());

        // This schema should have exactly 2 properties
        $properties = $schema->getAllProperties();
        $this->assertEquals(2, count($properties));

        // There should be a property named "modules": a "mixed object
        // array" with a type field "type"
        //
        // This property should be optional.
        $this->assertSchemaHasMixedObjectArrayProperty(
            $schema, 'modules', 'modules', 'type', IS_OPTIONAL);

        // There should be a property named "pageBanners": a "typed
        // array" with a type of "banner".
        //
        // This property should be optional.
        $this->assertSchemaHasTypedArrayProperty(
            $schema, 'pageBanners', 'pageBanners', 'banner', IS_OPTIONAL);
    }

    /**
     * Test that an exception is thrown if we load a model class with
     * no Model annotation.
     *
     * We throw an exception on this as it's almost certainly a sign
     * that developer has forgotten something.
     *
     * In practice this means that every class under your project's
     * Model namespace must have a Model annotation, since Shale will
     * try to load every class within that namespace.
     */
    public function testModelMissingModelAnnotation()
    {
        $this->expectException(LoadSchemaException::class);
        $modelMissingModelAnnotationFqcn =
            'Shale\\Test\\Support\\Mock\\BrokenModel\\ModelMissingModelAnnotation';
        $modelFqcns = [$modelMissingModelAnnotationFqcn];
        $this->schemaEngine->loadSchemaForModels($modelFqcns);
    }

    /**
     * Test that an exception is thrown if we load a model class with
     * more than one Model annotation.
     *
     * We currently don't allow this in our schema system: model classes
     * must have *exactly one* Model annotation.
     *
     * We could silently pick one of the annotations as the "correct" or
     * "preferred" one, but we decided it's better to break loudly,
     * since multiple annotations is almost certainly a sign that a
     * developer's made a terrible mistake.
     */
    public function testModelWithTooManyModelAnnotations()
    {
        $this->expectException(LoadSchemaException::class);
        $modelWithTooManyModelAnnotations =
            'Shale\\Test\\Support\\Mock\\BrokenModel\\ModelWithTooManyModelAnnotations';
        $modelFqcns = [$modelWithTooManyModelAnnotations];
        $this->schemaEngine->loadSchemaForModels($modelFqcns);
    }

    /**
     * Test that we can load a model class where one of its properties
     * has no annotations.
     *
     * This is actually valid within our system, and should load
     * correctly. The schema should simply not include any mention of
     * the un-annotated property, as it shouldn't be mapped.
     *
     * We allow this case because there may be legitimate reasons for a
     * model to have properties that hold data which isn't directly
     * sourced from the JSON API.
     */
    public function testModelWithUnannotatedProperty()
    {
        $modelWithUnannotatedProperty =
            'Shale\\Test\\Support\\Mock\\Model\\ModelWithUnannotatedProperty';
        $modelFqcns = [$modelWithUnannotatedProperty];

        $this->schemaEngine->loadSchemaForModels($modelFqcns);
        $schemas = $this->schemaEngine->getAllModelSchemas();

        // Check model name in schema, and model's FQCN
        $schema = $schemas['model_with_unannotated_property'];
        $this->assertEquals(
            $modelWithUnannotatedProperty,
            $schema->getModelFqcn());

        // The schema representation of the model should have exactly 1
        // property, as only one of the properties was annotated
        $properties = $schema->getAllProperties();
        $this->assertEquals(1, count($properties));

        // The one property should be "annotatedProperty"
        $this->assertSchemaHasPropertyWith(
            $schema, 'annotatedProperty', 'annotatedProperty', 'string');
    }

    /**
     * Test that an exception is thrown if we load a model class with
     * more than one property annotation.
     *
     * We currently don't allow this in our schema system: properties on
     * model classes must have *exactly one* property annotation.
     *
     * We could silently pick one of the annotations as the "correct" or
     * "preferred" one, but we decided it's better to break loudly,
     * since multiple annotations is almost certainly a sign that a
     * developer's made a terrible mistake.
     */
    public function testModelWithTooManyPropertyAnnotations()
    {
        $this->expectException(LoadSchemaException::class);
        $modelWithTooManyPropertyAnnotations =
            'Shale\\Test\\Support\\Mock\\BrokenModel\\ModelWithTooManyPropertyAnnotations';
        $modelFqcns = [$modelWithTooManyPropertyAnnotations];
        $this->schemaEngine->loadSchemaForModels($modelFqcns);
    }

    protected function assertSchemaHasPropertyWith(
        SchemaTypeInterface $schema,
        string $nameInTransport,
        string $nameInModel,
        string $typeName,
        bool $isRequired = IS_REQUIRED
    ) {
        $property = $schema->getPropertyByNameInTransport($nameInTransport);
        $propertyDescription = 'Property "' . $nameInTransport . '"';

        $this->assertOnPropertyAttribute($propertyDescription,
            'nameInTransport', $property->getNameInTransport(), $nameInTransport);
        $this->assertOnPropertyAttribute($propertyDescription,
            'nameInModel', $property->getNameInModel(), $nameInModel);
        $this->assertOnPropertyAttribute($propertyDescription,
            'type', $property->getValueType()->getName(), $typeName);
        $this->assertOnPropertyAttribute($propertyDescription,
            'isRequired value',
            $property->isRequired(),
            $isRequired,
            DO_NOT_QUOTE_VALUES
        );
    }

    protected function assertSchemaHasTypedArrayProperty(
        SchemaTypeInterface $schema,
        string $nameInTransport,
        string $nameInModel,
        string $itemTypeName,
        bool $isRequired = IS_REQUIRED
    ) {
        $property = $schema->getPropertyByNameInTransport($nameInTransport);
        $propertyDescription =
            'Typed-array property "' . $nameInTransport . '"';

        $this->assertOnPropertyAttribute($propertyDescription,
            'nameInTransport', $property->getNameInTransport(), $nameInTransport);
        $this->assertOnPropertyAttribute($propertyDescription,
            'nameInModel', $property->getNameInModel(), $nameInModel);
        $this->assertOnPropertyAttribute($propertyDescription,
            'item type',
            $property->getValueType()->getItemType()->getName(),
            $itemTypeName);
        $this->assertOnPropertyAttribute($propertyDescription,
            'isRequired value',
            $property->isRequired(),
            $isRequired,
            DO_NOT_QUOTE_VALUES
        );
    }

    protected function assertSchemaHasMixedObjectArrayProperty(
        SchemaTypeInterface $schema,
        string $nameInTransport,
        string $nameInModel,
        string $typeFieldName,
        bool $isRequired = IS_REQUIRED
    ) {
        $property = $schema->getPropertyByNameInTransport($nameInTransport);
        $propertyDescription =
            'Mixed-object-array property "' . $nameInTransport . '"';

        $this->assertOnPropertyAttribute($propertyDescription,
            'nameInTransport', $property->getNameInTransport(), $nameInTransport);
        $this->assertOnPropertyAttribute($propertyDescription,
            'nameInModel', $property->getNameInModel(), $nameInModel);
        $this->assertOnPropertyAttribute($propertyDescription,
            'type field name',
            $property->getValueType()->getTypeFieldName(),
            $typeFieldName);
        $this->assertOnPropertyAttribute($propertyDescription,
            'isRequired value',
            $property->isRequired(),
            $isRequired,
            DO_NOT_QUOTE_VALUES
        );
    }

    /**
     * This asserts on some attribute of a schema property object, and
     * gives a suitable message if the assertion fails.
     *
     * The failure message will be of the form:
     *
     *     <property description> has <attribute description> of
     *     "<actual value>", expected to see "<expected value>"
     *
     * For example:
     *
     *     Typed-array property "photoTags" has type of "string",
     *     expected to see "tag"
     *
     * If $quote is given as DO_NOT_QUOTE_VALUES, then
     * $actualValue and $expectedValue will not be wrapped in
     * quotation marks.
     */
    protected function assertOnPropertyAttribute(
        string $propertyDescription,
        string $attributeDescription,
        $actualValue,
        $expectedValue,
        bool $quote = QUOTE_VALUES
    ) {
        if ($quote) {
            $q = '"';
        } else {
            $q = '';
        }

        $this->assertEquals(
            $expectedValue,
            $actualValue,
            (
                $propertyDescription . ' has ' . $attributeDescription .
                ' of ' . $q . $this->showValue($actualValue) . $q .
                ', expected to see ' .
                $q . $this->showValue($expectedValue) . $q
            )
        );
    }

    /**
     * Give a better string representation of boolean and null values.
     *
     * The default strval() or implicit conversion gives '1' for boolean
     * true and an empty string for boolean false.
     *
     * This gives 'true' for boolean true, 'false' for boolean false,
     * and 'null' for null.
     */
    protected function showValue($v): string
    {
        if ($v === true) {
            return 'true';
        } elseif ($v === false) {
            return 'false';
        } elseif ($v === null) {
            return 'null';
        } else {
            return strval($v);
        }
    }
}
