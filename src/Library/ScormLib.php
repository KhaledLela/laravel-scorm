<?php


namespace Peopleaps\Scorm\Library;


use DOMDocument;
use Peopleaps\Scorm\Entity\Sco;
use Peopleaps\Scorm\Exception\InvalidScormArchiveException;
use Illuminate\Support\Str;

class ScormLib
{
    /**
     * Looks for the organization to use.
     *
     * @return array of Sco
     *
     * @throws InvalidScormArchiveException If a default organization
     *                                      is defined and not found
     */
    public function parseOrganizationsNode(DOMDocument $dom)
    {
        $organizationsList = $dom->getElementsByTagName('organizations');
        $resources = $dom->getElementsByTagName('resource');

        \Log::info('parseOrganizationsNode: Found ' . $organizationsList->length . ' organizations and ' . $resources->length . ' resources');

        if ($organizationsList->length > 0) {
            $organizations = $organizationsList->item(0);
            $organization = $organizations->firstChild;

            if (
                !is_null($organizations->attributes)
                && !is_null($organizations->attributes->getNamedItem('default'))
            ) {
                $defaultOrganization = $organizations->attributes->getNamedItem('default')->nodeValue;
                \Log::info('parseOrganizationsNode: Default organization found: ' . $defaultOrganization);
            } else {
                $defaultOrganization = null;
                \Log::info('parseOrganizationsNode: No default organization defined');
            }
            // No default organization is defined
            if (is_null($defaultOrganization)) {
                \Log::info('parseOrganizationsNode: Looking for first organization...');
                while (
                    !is_null($organization)
                    && 'organization' !== $organization->nodeName
                ) {
                    $organization = $organization->nextSibling;
                }

                if (is_null($organization)) {
                    \Log::info('parseOrganizationsNode: No organization found, falling back to parseResourceNodes');
                    return $this->parseResourceNodes($resources);
                } else {
                    \Log::info('parseOrganizationsNode: Found organization, proceeding with parseItemNodes');
                }
            }
            // A default organization is defined
            // Look for it
            else {
                \Log::info('parseOrganizationsNode: Looking for default organization: ' . $defaultOrganization);
                while (
                    !is_null($organization)
                    && ('organization' !== $organization->nodeName
                        || is_null($organization->attributes->getNamedItem('identifier'))
                        || $organization->attributes->getNamedItem('identifier')->nodeValue !== $defaultOrganization)
                ) {
                    $organization = $organization->nextSibling;
                }

                if (is_null($organization)) {
                    \Log::error('parseOrganizationsNode: Default organization not found: ' . $defaultOrganization);
                    throw new InvalidScormArchiveException('default_organization_not_found_message');
                } else {
                    \Log::info('parseOrganizationsNode: Found default organization, proceeding with parseItemNodes');
                }
            }

            \Log::info('parseOrganizationsNode: Calling parseItemNodes');
            return $this->parseItemNodes($organization, $resources);
        } else {
            \Log::error('parseOrganizationsNode: No organizations found in manifest');
            throw new InvalidScormArchiveException('no_organization_found_message');
        }
    }

    /**
     * Creates defined structure of SCOs.
     *
     * @return array of Sco
     *
     * @throws InvalidScormArchiveException
     */
    private function parseItemNodes(\DOMNode $source, \DOMNodeList $resources, Sco $parentSco = null)
    {
        $item = $source->firstChild;
        $scos = [];
        $itemCount = 0;

        \Log::info('parseItemNodes: Starting to parse items from organization');

        while (!is_null($item)) {
            if ('item' === $item->nodeName) {
                $itemCount++;
                \Log::info("parseItemNodes: Processing item #{$itemCount}");
                
                $sco = new Sco();
                $scos[] = $sco;
                $sco->setUuid(Str::uuid());
                $sco->setScoParent($parentSco);
                
                \Log::info("parseItemNodes: Created SCO #{$itemCount} with UUID: " . $sco->getUuid());
                
                $this->findAttrParams($sco, $item, $resources);
                $this->findNodeParams($sco, $item->firstChild);

                \Log::info("parseItemNodes: SCO #{$itemCount} - identifier: " . $sco->getIdentifier() . ", title: " . $sco->getTitle() . ", isBlock: " . ($sco->isBlock() ? 'true' : 'false'));

                if ($sco->isBlock()) {
                    \Log::info("parseItemNodes: SCO #{$itemCount} is a block, parsing children...");
                    $children = $this->parseItemNodes($item, $resources, $sco);
                    $sco->setScoChildren($children);
                    \Log::info("parseItemNodes: SCO #{$itemCount} has " . count($children) . " children");
                } else {
                    \Log::info("parseItemNodes: SCO #{$itemCount} is not a block, entryUrl: " . $sco->getEntryUrl());
                }
            }
            $item = $item->nextSibling;
        }

        \Log::info("parseItemNodes: Finished parsing, found {$itemCount} items, returning " . count($scos) . " SCOs");
        return $scos;
    }

    private function parseResourceNodes(\DOMNodeList $resources)
    {
        $scos = [];
        \Log::info('parseResourceNodes: Starting to parse ' . $resources->length . ' resources');

        foreach ($resources as $index => $resource) {
            \Log::info("parseResourceNodes: Processing resource #{$index}");

            if (!is_null($resource->attributes)) {
                \Log::info("parseResourceNodes: Resource #{$index} has attributes");
                
                // Try to get the adlcp namespace URI
                $adlcpNamespaceUri = $resource->lookupNamespaceUri('adlcp');
                \Log::info("parseResourceNodes: Resource #{$index} adlcp namespace URI: " . ($adlcpNamespaceUri ?: 'null'));
                
                // If adlcp namespace is not found, try common SCORM namespaces
                if (empty($adlcpNamespaceUri)) {
                    $adlcpNamespaceUri = 'http://www.adlnet.org/xsd/adlcp_v1p3';
                    \Log::info("parseResourceNodes: Using fallback adlcp namespace URI: {$adlcpNamespaceUri}");
                }
                
                $scormType = null;
                if (!empty($adlcpNamespaceUri)) {
                    $scormType = $resource->attributes->getNamedItemNS($adlcpNamespaceUri, 'scormType');
                    \Log::info("parseResourceNodes: Resource #{$index} scormType with namespace: " . ($scormType ? $scormType->nodeValue : 'null'));
                }

                // If still no scormType found, try without namespace (for SCORM 1.2)
                if (is_null($scormType)) {
                    $scormType = $resource->attributes->getNamedItem('scormType');
                    \Log::info("parseResourceNodes: Resource #{$index} scormType without namespace: " . ($scormType ? $scormType->nodeValue : 'null'));
                }

                if (!is_null($scormType) && 'sco' === $scormType->nodeValue) {
                    \Log::info("parseResourceNodes: Resource #{$index} is a SCO, processing...");
                    
                    $identifier = $resource->attributes->getNamedItem('identifier');
                    $href = $resource->attributes->getNamedItem('href');

                    \Log::info("parseResourceNodes: Resource #{$index} identifier: " . ($identifier ? $identifier->nodeValue : 'null'));
                    \Log::info("parseResourceNodes: Resource #{$index} href: " . ($href ? $href->nodeValue : 'null'));

                    if (is_null($identifier)) {
                        \Log::error("parseResourceNodes: Resource #{$index} has no identifier");
                        throw new InvalidScormArchiveException('sco_with_no_identifier_message');
                    }
                    if (is_null($href)) {
                        \Log::error("parseResourceNodes: Resource #{$index} has no href");
                        throw new InvalidScormArchiveException('sco_resource_without_href_message');
                    }
                    
                    $sco = new Sco();
                    $sco->setUuid(Str::uuid());
                    $sco->setBlock(false);
                    $sco->setVisible(true);
                    $sco->setIdentifier($identifier->nodeValue);
                    $sco->setTitle($identifier->nodeValue);
                    $sco->setEntryUrl($href->nodeValue);
                    $scos[] = $sco;
                    
                    \Log::info("parseResourceNodes: Successfully created SCO #{$index} with UUID: " . $sco->getUuid() . ", identifier: " . $sco->getIdentifier());
                } else {
                    \Log::info("parseResourceNodes: Resource #{$index} is not a SCO (scormType: " . ($scormType ? $scormType->nodeValue : 'null') . ")");
                }
            } else {
                \Log::info("parseResourceNodes: Resource #{$index} has no attributes");
            }
        }

        \Log::info('parseResourceNodes: Finished parsing, found ' . count($scos) . ' SCOs');
        return $scos;
    }

    /**
     * Initializes parameters of the SCO defined in attributes of the node.
     * It also look for the associated resource if it is a SCO and not a block.
     *
     * @throws InvalidScormArchiveException
     */
    private function findAttrParams(Sco $sco, \DOMNode $item, \DOMNodeList $resources)
    {
        $identifier = $item->attributes->getNamedItem('identifier');
        $isVisible = $item->attributes->getNamedItem('isvisible');
        $identifierRef = $item->attributes->getNamedItem('identifierref');
        $parameters = $item->attributes->getNamedItem('parameters');

        // throws an Exception if identifier is undefined
        if (is_null($identifier)) {
            throw new InvalidScormArchiveException('sco_with_no_identifier_message');
        }
        $sco->setIdentifier($identifier->nodeValue);

        // visible is true by default
        if (!is_null($isVisible) && 'false' === $isVisible) {
            $sco->setVisible(false);
        } else {
            $sco->setVisible(true);
        }

        // set parameters for SCO entry resource
        if (!is_null($parameters)) {
            $sco->setParameters($parameters->nodeValue);
        }

        // check if item is a block or a SCO. A block doesn't define identifierref
        if (is_null($identifierRef)) {
            $sco->setBlock(true);
        } else {
            $sco->setBlock(false);
            // retrieve entry URL
            $sco->setEntryUrl($this->findEntryUrl($identifierRef->nodeValue, $resources));
        }
    }

    /**
     * Initializes parameters of the SCO defined in children nodes.
     */
    private function findNodeParams(Sco $sco, \DOMNode $item)
    {
        while (!is_null($item)) {
            switch ($item->nodeName) {
                case 'title':
                    $sco->setTitle($item->nodeValue);
                    break;
                case 'adlcp:masteryscore':
                    $sco->setScoreToPassInt($item->nodeValue);
                    break;
                case 'adlcp:maxtimeallowed':
                case 'imsss:attemptAbsoluteDurationLimit':
                    $sco->setMaxTimeAllowed($item->nodeValue);
                    break;
                case 'adlcp:timelimitaction':
                case 'adlcp:timeLimitAction':
                    $action = strtolower($item->nodeValue);

                    if (
                        'exit,message' === $action
                        || 'exit,no message' === $action
                        || 'continue,message' === $action
                        || 'continue,no message' === $action
                    ) {
                        $sco->setTimeLimitAction($action);
                    }
                    break;
                case 'adlcp:datafromlms':
                case 'adlcp:dataFromLMS':
                    $sco->setLaunchData($item->nodeValue);
                    break;
                case 'adlcp:prerequisites':
                    $sco->setPrerequisites($item->nodeValue);
                    break;
                case 'imsss:minNormalizedMeasure':
                    $sco->setScoreToPassDecimal($item->nodeValue);
                    break;
                case 'adlcp:completionThreshold':
                    if ($item->nodeValue && !is_nan($item->nodeValue)) {
                        $sco->setCompletionThreshold(floatval($item->nodeValue));
                    }
                    break;
            }
            $item = $item->nextSibling;
        }
    }

    /**
     * Searches for the resource with the given id and retrieve URL to its content.
     *
     * @return string URL to the resource associated to the SCO
     *
     * @throws InvalidScormArchiveException
     */
    public function findEntryUrl($identifierref, \DOMNodeList $resources)
    {
        foreach ($resources as $resource) {
            $identifier = $resource->attributes->getNamedItem('identifier');

            if (!is_null($identifier)) {
                $identifierValue = $identifier->nodeValue;

                if ($identifierValue === $identifierref) {
                    $href = $resource->attributes->getNamedItem('href');

                    if (is_null($href)) {
                        throw new InvalidScormArchiveException('sco_resource_without_href_message');
                    }

                    return $href->nodeValue;
                }
            }
        }
        throw new InvalidScormArchiveException('sco_without_resource_message');
    }
}
