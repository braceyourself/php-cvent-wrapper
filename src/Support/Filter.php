<?php namespace Braceyourself\Cvent\Support;

use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;

class Filter implements Arrayable
{
    private $column;
    private $operator;
    private $value;

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
            'Field'    => $this->column,
            'Operator' => $this->getOperator(),
            'Value'    => $this->getValue(),
        ];
    }

    /**
     * @return string
     * @throws \Exception
     */
    private function getOperator(): string
    {
        switch (strtolower($this->operator)) {
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
}