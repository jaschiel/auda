<?php
/*
 * Copyright (c) 2024. Artisan Software Consulting. All rights reserved.
 */

namespace auda;

/**
 * @name auda.php
 * @author James Schiel
 * @date March 8, 2024
 * @copyright Artisan Software Consulting
 * @version 1
 * @package
 * @description
 *
 * 2024-Apr-16 - Schiel - bug fix dealing with the array brackets being used as part of the key.
 * 2024-Mar-19 - Schiel - bug fix dealing with single dimension unnamed arrays.
 * 2024-Mar-17 - Schiel - bug fix in FlattenArray.
 * 2024-Mar-16 - Schiel - added a clear method (initially used in a redirect); moved into a separate directory; I want to try to keep
 *                   this functionality as independent of the sierra architecture as possible.
 * 2024-Mar-9 - Schiel - modified to convert "$$" in values to "/" since it is a security risk to accept encoded slashes in the URL.
 */
final class auda
{
    private const TRIM_CHARACTERS = " ]";
    private array $theAuda;

    public function __construct()
    {
        $this->clear();
    }

    public function getAll(): array
    {
        return $this->theAuda;
    }

    public function add(string $name, mixed $rawValue, bool $toLower = true, bool $convertDollarsToSlashes = true): auda
    {
        $this->setNestedValue($this->theAuda, $this->correctedName($toLower, $name), $this->preparedValue($convertDollarsToSlashes, $rawValue));
        return $this;
    }

    public function addFile(string $name, mixed $rawValue, string $tempName): auda
    {
        $this->setNestedValue($this->theAuda, $this->correctedName(false, $name), $this->preparedValue(false, $rawValue, false, $tempName));
        return $this;
    }

    public function addProtected(string $name, mixed $rawValue, bool $toLower = true, bool $convertDollarsToSlashes = true): auda
    {
        $this->setNestedValue($this->theAuda, $this->correctedName($toLower, $name), $this->preparedValue($convertDollarsToSlashes, $rawValue, true));
        return $this;
    }

    public function addQuery(string $query): static
    {
        $queryStringValues = [];
        parse_str($query, $queryStringValues);

        foreach($queryStringValues as $key => $value) {
            $this->add($key, $value);
        }

        return $this;
    }

    /**
     * Receive the RAW JSON data from the request by reading the contents of the input stream and decoding it.
     * If the JSON is valid, an array representation of the JSON data is returned. Otherwise, an empty array is returned.
     *
     * @return array An array representation of the JSON data or an empty array if the JSON is invalid
     */
    private function receiveRAWJsonData(): array
    {
        //Receive the RAW post data.
        $content = trim(file_get_contents("php://input"));
        $decoded = json_decode($content, true);

        //If json_decode failed, the JSON is invalid.
        if (is_array($decoded)) {
            return $decoded;
        } else {
            return [];
        }
    }

    /**
     * Injects the arguments fetched from the request body into the `$theAuda` array if the content type is either "application/json" or "text/plain".
     * Modified the routine so that the last assignment to a specific key wins; older values are lost.
     *
     * @param string $contentType The content type of the request body
     * @param bool $toLower
     * @return void
     */
    public function addFetch(string $contentType, bool $toLower = true): void
    {
        $contentTypePart = substr($contentType, 0, strpos($contentType, ";"));
        if (in_array($contentTypePart,["","application/json","text/plain","multipart/form-data"])) {
            $jsonArgs = $this->receiveRAWJsonData();
            foreach ($jsonArgs as $key => $value) {
                $this->add($key, $value, $toLower);
            }
        }
        if ($contentTypePart == "multipart/form-data") {
            if (isset($_FILES)) {
                foreach ($_FILES as $name => $file) {
                    $this->addFile($name, $file["full_path"], $file["tmp_name"]);
                }
            }
        }
    }

    public function __toString(): string
    {
        $response = "AUDA=>";
        foreach ($this->theAuda as $name => $value) {
            if (is_string($value)) {
                $response .= "{$name}={$value},";
            } else {
                $response .= "{$name}=object,";
            }
        }
        return $response;
    }

    public function get($name, bool $toLower = true): mixed
    {
        $name = ($toLower) ? strtolower($name) : $name;
        $parts = explode('.', $name);

        // If the name concludes with "[]", an array is requested
        if (preg_match('/\[\]$/', end($parts))) {
            $parts[key($parts)] = rtrim(end($parts), '[]');
        }

        $value = $this->theAuda;

        foreach ($parts as $part) {
            if (isset($value[$part])) {
                $value = $value[$part];
            } else {
                return null; // The value doesn't exist
            }
        }

        if (is_array($value)) {
            $result =  $this->flattenArray($value);
        } else {
            /** @var audaValue $value */
            $result = $value->getValue();
        }

        if (is_array($result) && sizeof($result) === 1 && is_array($result[0])) {
            $result = $result[0];
        }

        return $result;
    }

    public function getElement($name, bool $toLower = true): ?audaValue
    {
        $name = ($toLower) ? strtolower($name) : $name;
        $parts = explode('.', $name);

        // If the name concludes with "[]", an array is requested
        if (preg_match('/\[\]$/', end($parts))) {
            $parts[key($parts)] = rtrim(end($parts), '[]');
        }

        $value = $this->theAuda;

        foreach ($parts as $part) {
            if (isset($value[$part])) {
                $value = $value[$part];
            } else {
                return null; // The value doesn't exist
            }
        }
        return $value;
    }

    /**
     * Clear the array of arguments.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->theAuda = [];
    }

    /**
     *
     * PRIVATE METHODS
     *
     */

    /**
     * @param bool $toLower
     * @param string $name
     * @return string[]
     */
    private function correctedName(bool $toLower, string $name): array
    {
        return preg_split('#(?=\[[^\]]*\])#', ($toLower) ? strtolower($name) : $name);
    }

    /**
     * @param array $data
     * @param array $names
     * @param mixed $value
     * @return void
     */
    private function setNestedValue(array &$data, array $names, audaValue $value): void
    {
        $keyPart = array_shift($names);

        if (sizeof($names) === 0 or $keyPart === "" or $keyPart === "[]") {
            if ($keyPart === "") {
                $data = $value;
            } else if ($keyPart === "[]") {
                $data[] = $value;
            } else {
                if (!isset($data[$keyPart]) || !$data[$keyPart]->isProtected()) {
                    // modification to remove array brackets when used as a key
                    if (str_starts_with($keyPart, "[") && str_ends_with($keyPart, "]")) {
                        $keyPart = substr($keyPart, 1, -1);
                    }
                    $data[$keyPart] = $value;
                }
            }
        } else {
            // If the key does not exist in the array, initialize it as an empty array
            if (!isset($data[$keyPart])) {
                $data[$keyPart] = [];
            }
            $this->setNestedValue($data[$keyPart], $names, $value);
        }
    }

    private function preparedValue(bool $convertDollarsToSlashes, mixed $value, bool $protected = false, ?string $tempFileName = null): audaValue
    {
        if ($convertDollarsToSlashes && is_string($value)) {
            $value = str_replace('$$', '/', $value);
        }
        $theValue = new audaValue($protected, $value);
        if ($tempFileName) {
            $theValue->setFileTempName($tempFileName);
        }
        return $theValue;
    }

    /**
     * @param array $value
     * @return array|string This routine converts arrays of audaValue objects into an array with only the user-anticipated values.
     * This routine converts arrays of audaValue objects into an array with only the user-anticipated values.
     */
    private function flattenArray(array $value): array|string
    {
        $result = [];
        foreach ($value as $key => $val) {
            if (is_array($val)) {
                $key = $this->removeBracketsAroundKey($key);
                $result[$key] = $this->flattenArray($val);
            } else {
                /** @var audaValue $val */
                $result[$key] = $val->getValue();
//                $result = $val->getValue();
            }
        }
        return $result;
    }

    /**
     * @param int|string $key
     * @return int|string
     */
    public function removeBracketsAroundKey(int|string $key): string|int
    {
        if (substr($key, 0, 1) === "[") {
            $key = substr($key, 1);
        }
        if (substr($key, strlen($key) - 1, 1) === "]") {
            $key = substr($key, 0, strlen($key) - 1);
        }
        return $key;
    }
}