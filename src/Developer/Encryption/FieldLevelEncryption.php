<?php

namespace Mastercard\Developer\Encryption;

use Mastercard\Developer\Json\JsonPath;
use Mastercard\Developer\Utils\EncodingUtils;
use phpseclib\Crypt\AES;

/**
 * Performs field level encryption on HTTP payloads.
 * @package Mastercard\Developer\Encryption
 */
class FieldLevelEncryption {

    private function __construct() {
        // This class can't be instantiated
    }

    /**
     * Encrypt parts of a JSON payload using the given parameters and configuration.
     * @param string                          $payload A JSON string
     * @param FieldLevelEncryptionConfig      $config  A FieldLevelEncryptionConfig instance
     * @param FieldLevelEncryptionParams|null $params  A FieldLevelEncryptionParams instance
     * @see FieldLevelEncryptionConfig
     * @see FieldLevelEncryptionParams
     * @return string The updated payload
     * @throws EncryptionException
     */
    public static function encryptPayload($payload, $config, $params = null) {
        try {
            // Parse the given payload
            $payloadJsonObject = json_decode($payload);

            // Perform encryption (if needed)
            foreach ($config->getEncryptionPaths() as $jsonPathIn => $jsonPathOut) {
                $payloadJsonObject = self::encryptPayloadPath($payloadJsonObject, $jsonPathIn, $jsonPathOut, $config, $params);
            }

            // Return the updated payload
            return json_encode($payloadJsonObject);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (EncryptionException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new EncryptionException('Payload encryption failed!', $e);
        }
    }

    /**
     * Decrypt parts of a JSON payload using the given parameters and configuration.
     * @param string                          $payload A JSON string
     * @param FieldLevelEncryptionConfig      $config  A FieldLevelEncryptionConfig instance
     * @param FieldLevelEncryptionParams|null $params  A FieldLevelEncryptionParams instance
     * @see FieldLevelEncryptionConfig
     * @see FieldLevelEncryptionParams
     * @return string The updated payload
     * @throws EncryptionException
     */
    public static function decryptPayload($payload, $config, $params = null) {
        try {
            // Parse the given payload
            $payloadJsonObject = json_decode($payload);

            // Perform decryption (if needed)
            foreach ($config->getDecryptionPaths() as $jsonPathIn => $jsonPathOut) {
                $payloadJsonObject = self::decryptPayloadPath($payloadJsonObject, $jsonPathIn, $jsonPathOut, $config, $params);
            }

            // Return the updated payload
            return json_encode($payloadJsonObject);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (EncryptionException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new EncryptionException('Payload decryption failed!', $e);
        }
    }

    /**
     * @param \stdClass                       $payloadJsonObject
     * @param string                          $jsonPathIn
     * @param string                          $jsonPathOut
     * @param FieldLevelEncryptionConfig      $config
     * @param FieldLevelEncryptionParams|null $params
     * @throws EncryptionException
     */
    private static function encryptPayloadPath($payloadJsonObject, $jsonPathIn, $jsonPathOut, $config, $params) {

        $inJsonObject = self::readJsonElement($payloadJsonObject, $jsonPathIn);
        if (is_null($inJsonObject)) {
            // Nothing to encrypt
            return $payloadJsonObject;
        }

        if (empty($params)) {
            // Generate encryption params
            $params = FieldLevelEncryptionParams::generate($config);
        }

        // Encrypt data at the given JSON path
        $inJsonString = self::sanitizeJson(self::toJsonString($inJsonObject));
        $encryptedValueBytes = self::encryptBytes($params->getSecretKeyBytes(), $params->getIvBytes(), $inJsonString);
        $encryptedValue = EncodingUtils::encodeBytes($encryptedValueBytes, $config->getFieldValueEncoding());

        // Delete data in clear
        if ('$' !== $jsonPathIn) {
            JsonPath::delete($payloadJsonObject, $jsonPathIn);
        } else {
            $payloadJsonObject = json_decode('{}');
        }

        // Add encrypted data and encryption fields at the given JSON path
        $outJsonObject = self::checkOrCreateOutObject($payloadJsonObject, $jsonPathOut);
        $outJsonObject->{$config->getEncryptedValueFieldName()} = $encryptedValue;
        if (!empty($config->getIvFieldName())) {
            $outJsonObject->{$config->getIvFieldName()} = $params->getIvValue();
        }
        if (!empty($config->getEncryptedKeyFieldName())) {
            $outJsonObject->{$config->getEncryptedKeyFieldName()} = $params->getEncryptedKeyValue();
        }
        if (!empty($config->getEncryptionCertificateFingerprintFieldName())) {
            $outJsonObject->{$config->getEncryptionCertificateFingerprintFieldName()} = $config->getEncryptionCertificateFingerprint();
        }
        if (!empty($config->getEncryptionKeyFingerprintFieldName())) {
            $outJsonObject->{$config->getEncryptionKeyFingerprintFieldName()} = $config->getEncryptionKeyFingerprint();
        }
        if (!empty($config->getOaepPaddingDigestAlgorithmFieldName())) {
            $outJsonObject->{$config->getOaepPaddingDigestAlgorithmFieldName()} = $params->getOaepPaddingDigestAlgorithmValue();
        }
        return $payloadJsonObject;
    }

    /**
     * @param \stdClass                       $payloadJsonObject
     * @param string                          $jsonPathIn
     * @param string                          $jsonPathOut
     * @param FieldLevelEncryptionConfig      $config
     * @param FieldLevelEncryptionParams|null $params
     * @throws EncryptionException
     */
    private static function decryptPayloadPath($payloadJsonObject, $jsonPathIn, $jsonPathOut, $config, $params) {

        $inJsonObject = self::readJsonObject($payloadJsonObject, $jsonPathIn);
        if (is_null($inJsonObject)) {
            // Nothing to decrypt
            return $payloadJsonObject;
        }

        // Read and remove encrypted data and encryption fields at the given JSON path
        $encryptedValueJsonElement = self::readAndDeleteJsonKey($inJsonObject, $config->getEncryptedValueFieldName());
        if (empty($encryptedValueJsonElement)) {
            // Nothing to decrypt
            return $payloadJsonObject;
        }

        if (!$config->useHttpPayloads() && empty($params)) {
            throw new \InvalidArgumentException('Encryption params have to be set when not stored in HTTP payloads!');
        }

        if (empty($params)) {
            // Read encryption params from the payload
            $oaepDigestAlgorithmJsonElement = self::readAndDeleteJsonKey($inJsonObject, $config->getOaepPaddingDigestAlgorithmFieldName());
            $oaepDigestAlgorithm = empty($oaepDigestAlgorithmJsonElement) ? $config->getOaepPaddingDigestAlgorithm() : $oaepDigestAlgorithmJsonElement;
            $encryptedKeyJsonElement = self::readAndDeleteJsonKey($inJsonObject, $config->getEncryptedKeyFieldName());
            $ivJsonElement = self::readAndDeleteJsonKey($inJsonObject, $config->getIvFieldName());
            self::readAndDeleteJsonKey($inJsonObject, $config->getEncryptionCertificateFingerprintFieldName());
            self::readAndDeleteJsonKey($inJsonObject, $config->getEncryptionKeyFingerprintFieldName());
            $params = new FieldLevelEncryptionParams($config, $ivJsonElement, $encryptedKeyJsonElement, $oaepDigestAlgorithm);
        }

        // Decrypt data
        $encryptedValueBytes = EncodingUtils::decodeValue($encryptedValueJsonElement, $config->getFieldValueEncoding());
        $decryptedValueBytes = self::decryptBytes($params->getSecretKeyBytes(), $params->getIvBytes(), $encryptedValueBytes);

        // Add decrypted data at the given JSON path
        $decryptedValue = self::sanitizeJson($decryptedValueBytes);
        $outJsonObject = self::checkOrCreateOutObject($payloadJsonObject, $jsonPathOut);
        $payloadJsonObject = self::addDecryptedDataToPayload($payloadJsonObject, $jsonPathOut, $outJsonObject, $decryptedValue);

        // Remove the input if now empty
        $inJsonElement = self::readJsonElement($payloadJsonObject, $jsonPathIn);
        if (empty((array)$inJsonElement) && '$' !== $jsonPathIn) {
            JsonPath::delete($payloadJsonObject, $jsonPathIn);
        }
        return $payloadJsonObject;
    }

    /**
     * @param \stdClass $payloadJsonObject
     * @param string    $jsonPathOut
     * @param \stdClass $outJsonObject
     * @param mixed     $decryptedValue
     */
    private static function addDecryptedDataToPayload($payloadJsonObject, $jsonPathOut, $outJsonObject, $decryptedValue) {
        $decryptedValueJsonElement = json_decode($decryptedValue);
        if (is_null($decryptedValueJsonElement)) {
            // 'json_decode' returns null for strings
            $decryptedValueJsonElement = $decryptedValue;
        }

        if ('$' === $jsonPathOut && is_array($decryptedValueJsonElement)) {
            return $decryptedValueJsonElement;
        }

        if (!is_object($decryptedValueJsonElement)) {
            // Array or primitive: overwrite
            $parentPath = JsonPath::getParentPath($jsonPathOut);
            $elementKey = JsonPath::getElementKey($jsonPathOut);
            $parentObject = JsonPath::find($payloadJsonObject, $parentPath);
            $parentObject->$elementKey = $decryptedValueJsonElement;
            return $payloadJsonObject;
        }

        // Object: merge
        foreach ($decryptedValueJsonElement as $key => $value) {
            $outJsonObject->$key = $value;
        }
        return $payloadJsonObject;
    }

    /**
     * @param \stdClass $payloadJsonObject
     * @param string    $jsonPath
     * @return mixed
     */
    private static function readJsonElement($payloadJsonObject, $jsonPath) {
        return JsonPath::find($payloadJsonObject, $jsonPath);
    }

    /**
     * @param \stdClass $payloadJsonObject
     * @param string    $jsonPath
     * @throws \InvalidArgumentException
     * @return mixed
     */
    private static function readJsonObject($payloadJsonObject, $jsonPath) {
        $inJsonElement = self::readJsonElement($payloadJsonObject, $jsonPath);
        if (is_null($inJsonElement)) {
            return null;
        }
        if (!is_object($inJsonElement)) {
            throw new \InvalidArgumentException('JSON object expected at path: \'' . $jsonPath . '\'!');
        }
        return $inJsonElement;
    }

    /**
     * @param \stdClass $payloadJsonObject
     * @param string    $jsonPathOut
     * @throws \InvalidArgumentException
     * @return mixed
     */
    private static function checkOrCreateOutObject($payloadJsonObject, $jsonPathOut) {
        $outJsonObject = self::readJsonObject($payloadJsonObject, $jsonPathOut);
        if (!is_null($outJsonObject)) {
            // Object already exists
            return $outJsonObject;
        }

        // Path does not exist: if parent exists then we create a new object under the parent
        $parentJsonPath = JsonPath::getParentPath($jsonPathOut);
        $parentJsonObject = self::readJsonObject($payloadJsonObject, $parentJsonPath);
        if (is_null($parentJsonObject)) {
            throw new \InvalidArgumentException('Parent path not found in payload: \'' . $parentJsonPath . '\'!');
        }
        $elementKey = JsonPath::getElementKey($jsonPathOut);
        $parentJsonObject->$elementKey = json_decode('{}');
        return $parentJsonObject->$elementKey;
    }

    /**
     * @param \stdClass $object
     * @param string    $key
     * @return mixed
     */
    private static function readAndDeleteJsonKey($object, $key) {
        if (empty($key) || false === property_exists($object, $key)) {
            // Do nothing
            return null;
        }
        $value = $object->$key;
        unset($object->$key);
        return $value;
    }

    /**
     * @param string $key
     * @param string $iv
     * @param string $bytes
     * @throws EncryptionException
     * @return string
     */
    private static function encryptBytes($key, $iv, $bytes) {
        $aes = new AES();
        $aes->setKey($key);
        $aes->setIV($iv);
        $encryptedBytes = $aes->encrypt($bytes);
        if (false === $encryptedBytes) {
            throw new EncryptionException('Failed to encrypt bytes!');
        }
        return $encryptedBytes;
    }

    /**
     * @param string $key
     * @param string $iv
     * @param string $encryptedBytes
     * @throws EncryptionException
     * @return string
     */
    private static function decryptBytes($key, $iv, $encryptedBytes) {
        $aes = new AES();
        $aes->setKey($key);
        $aes->setIV($iv);
        $bytes = $aes->decrypt($encryptedBytes);
        if (false === $bytes) {
            throw new EncryptionException('Failed to decrypt bytes with the provided key and IV!');
        }
        return $bytes;
    }

    /**
     * @param mixed $object
     * @return mixed
     */
    private static function toJsonString($object) {
        if (is_null($object)) {
            throw new \InvalidArgumentException('Can\'t get a JSON string from a null object!');
        }
        if (is_string($object)) {
            return $object;
        }
        return json_encode($object);
    }

    /**
     * @param string $json
     * @return string
     */
    private static function sanitizeJson($json) {
        $json = str_replace("\n", '', $json);
        $json = str_replace("\r", '', $json);
        $json = str_replace("\t", '', $json);
        return str_replace("\r\n", '', $json);
    }
}
