<?php

if ( ! function_exists('getAdditionalInfo')) {
    function getAdditionalInfo($key, \Illuminate\Validation\Validator $validator, $item)
    {
        $errorMessage = sprintf('%s %s', trans('imports.ordinal_number'), $key);

        if ( ! empty($item['title']) && ! $validator->errors()->get('title')) {
            $errorMessage .= sprintf(', %s', $item['title']);
        }
        if ( ! empty($item['id']) && ! $validator->errors()->get('id')) {
            $errorMessage .= sprintf(', id %s', $item['id']);
        }
        if ( ! empty($item['barcode']) && ! $validator->errors()->get('barcode')) {
            $errorMessage .= sprintf(', barcode %s', $item['barcode']);
        }

        return [
            'object' => $errorMessage,
            'errors' => $validator->errors()->all()
        ];
    }
}

if ( ! function_exists('customAdditionalInfo')) {
    function customAdditionalInfo(string $errorMessage, ?array $errors): array
    {
        return [
            'object' => $errorMessage,
            'errors' => $errors
        ];
    }
}
