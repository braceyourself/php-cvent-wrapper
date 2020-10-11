<?php namespace Braceyourself\Cvent\Support;

use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;

class Filter implements Arrayable
{
    private $column;
    private $operator;
    private $value;
    /**
     * @var string[]
     */
    private array $operators = [
        'Equals',
        'Not Equal to',
        'Less than',
        'Greater than',
        'Less than or Equal to',
        'Greater than or Equal to',
        'Contains',
        'Does not Contain',
        'Starts with',
        'Includes',
        'Excludes'
    ];

    /**
     * Filter constructor.
     * @param $column
     * @param $operator
     * @param $value
     */
    public function __construct($column, $operator, $value = null)
    {
        $this->column = $column;
        $this->operator = func_num_args() === 2 ? '=' : $operator;
        $this->value = func_num_args() === 2 ? $operator : $value;
    }


    /**
     * @return array
     * @throws \Exception
     */
    public function toArray()
    {
        return [
            'Field'              => $this->column,
            'Operator'           => $this->getOperator(),
            $this->getValueKey() => $this->getValue(),
        ];
    }

    /**
     * @return string
     * @throws \Exception
     */
    private function getOperator(): string
    {
        if (in_array($this->operator, $this->operators)) {
            return $this->operator;
        }

        switch (strtolower($this->operator)) {
            case '!=':
                return 'Not Equal to';
            case '<=':
                return 'Less than or Equal to';
            case '>=':
                return 'Greater than or Equal to';
            case 'starts with':
                return 'Starts with';
            case '>':
            case 'greater than':
            case 'greater':
                return 'Greater than';
            case '<':
            case 'less than':
            case 'less':
                return 'Less than';
            case '=':
            case '==':
            case '===':
            case 'equal':
            case 'equals':
                return 'Equals';
            case 'in':
            case 'includes':
                return 'Includes';
            case 'excludes':
                return 'Excludes';
            default:
                throw new \Exception("Invalid operator value");
        }
    }

    /**
     * @return mixed|string|null
     */
    private function getValue()
    {
        if ($this->value instanceof Carbon) {
            return $this->value->utc()->setTimezone('GMT')->toDateTimeString();
        }

        return $this->value;
    }

    private function getValueKey()
    {
        return (gettype($this->getValue()) === 'array') ? 'ValueArray' : 'Value';
    }
}