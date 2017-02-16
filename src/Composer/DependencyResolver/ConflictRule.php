<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\DependencyResolver;

use Composer\Package\CompletePackage;
use Composer\Package\PackageInterface;
use Composer\Package\Link;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class ConflictRule implements Rule
{
    protected $literal1;
    protected $literal2;
    protected $bitfield;
    protected $reasonData;

    /**
     * @param int                   $literal1
     * @param int                   $literal2
     * @param int                   $reason     A RULE_* constant describing the reason for generating this rule
     * @param Link|PackageInterface $reasonData
     * @param array                 $job        The job this rule was created from
     */
    public function __construct($literal1, $literal2, $reason, $reasonData, $job = null)
    {
        if ($literal1 < $literal2) {
            $this->literal1 = $literal1;
            $this->literal2 = $literal2;
        } else {
            $this->literal1 = $literal2;
            $this->literal2 = $literal1;
        }

        $this->reasonData = $reasonData;

        if ($job) {
            $this->job = $job;
        }

        $this->bitfield = (0 << self::BITFIELD_DISABLED) |
            ($reason << self::BITFIELD_REASON) |
            (255 << self::BITFIELD_TYPE);
    }

    public function getLiterals()
    {
        return array($this->literal1, $this->literal2);
    }

    public function getHash()
    {
        $data = unpack('ihash', md5($this->literal1.','.$this->literal2, true));

        return $data['hash'];
    }

    public function getJob()
    {
        return isset($this->job) ? $this->job : null;
    }

    public function getReason()
    {
        return ($this->bitfield & (255 << self::BITFIELD_REASON)) >> self::BITFIELD_REASON;
    }

    public function getReasonData()
    {
        return $this->reasonData;
    }

    public function getRequiredPackage()
    {
        if ($this->getReason() === self::RULE_JOB_INSTALL) {
            return $this->reasonData;
        }

        if ($this->getReason() === self::RULE_PACKAGE_REQUIRES) {
            return $this->reasonData->getTarget();
        }
    }

    /**
     * Checks if this rule is equal to another one
     *
     * Ignores whether either of the rules is disabled.
     *
     * @param  Rule $rule The rule to check against
     * @return bool Whether the rules are equal
     */
    public function equals(Rule $rule)
    {
        if (2 != count($rule->getLiterals())) {
            return false;
        }

        if ($this->literal1 !== $rule->getLiterals()[0]) {
            return false;
        }

        if ($this->literal2 !== $rule->getLiterals()[1]) {
            return false;
        }

        return true;
    }

    public function setType($type)
    {
        $this->bitfield = ($this->bitfield & ~(255 << self::BITFIELD_TYPE)) | ((255 & $type) << self::BITFIELD_TYPE);
    }

    public function getType()
    {
        return ($this->bitfield & (255 << self::BITFIELD_TYPE)) >> self::BITFIELD_TYPE;
    }

    public function disable()
    {
        $this->bitfield = ($this->bitfield & ~(255 << self::BITFIELD_DISABLED)) | (1 << self::BITFIELD_DISABLED);
    }

    public function enable()
    {
        $this->bitfield = $this->bitfield & ~(255 << self::BITFIELD_DISABLED);
    }

    public function isDisabled()
    {
        return (bool) (($this->bitfield & (255 << self::BITFIELD_DISABLED)) >> self::BITFIELD_DISABLED);
    }

    public function isEnabled()
    {
        return !(($this->bitfield & (255 << self::BITFIELD_DISABLED)) >> self::BITFIELD_DISABLED);
    }

    public function isAssertion()
    {
        return false;
    }

    public function getPrettyString(Pool $pool, array $installedMap = array())
    {
        $ruleText = '';

        $ruleText .= $pool->literalToPrettyString($this->literal1, $installedMap);
        $ruleText .= '|';
        $ruleText .= $pool->literalToPrettyString($this->literal2, $installedMap);

        switch ($this->getReason()) {
            case self::RULE_INTERNAL_ALLOW_UPDATE:
                return $ruleText;

            case self::RULE_JOB_INSTALL:
                return "Install command rule ($ruleText)";

            case self::RULE_JOB_REMOVE:
                return "Remove command rule ($ruleText)";

            case self::RULE_PACKAGE_CONFLICT:
                $package1 = $pool->literalToPackage($this->literal1);
                $package2 = $pool->literalToPackage($this->literal2);

                return $package1->getPrettyString().' conflicts with '.$this->formatPackagesUnique($pool, array($package2)).'.';

            case self::RULE_PACKAGE_REQUIRES:
                $literals = $this->getLiterals();
                $sourceLiteral = array_shift($literals);
                $sourcePackage = $pool->literalToPackage($sourceLiteral);

                $requires = array();
                foreach ($literals as $literal) {
                    $requires[] = $pool->literalToPackage($literal);
                }

                $text = $this->reasonData->getPrettyString($sourcePackage);
                if ($requires) {
                    $text .= ' -> satisfiable by ' . $this->formatPackagesUnique($pool, $requires) . '.';
                } else {
                    $targetName = $this->reasonData->getTarget();

                    if ($targetName === 'php' || $targetName === 'php-64bit' || $targetName === 'hhvm') {
                        // handle php/hhvm
                        if (defined('HHVM_VERSION')) {
                            return $text . ' -> your HHVM version does not satisfy that requirement.';
                        }

                        if ($targetName === 'hhvm') {
                            return $text . ' -> you are running this with PHP and not HHVM.';
                        }

                        $packages = $pool->whatProvides($targetName);
                        $package = count($packages) ? current($packages) : phpversion();

                        if (!($package instanceof CompletePackage)) {
                            return $text . ' -> your PHP version ('.phpversion().') does not satisfy that requirement.';
                        }

                        $extra = $package->getExtra();

                        if (!empty($extra['config.platform'])) {
                            $text .= ' -> your PHP version ('.phpversion().') overridden by "config.platform.php" version ('.$package->getPrettyVersion().') does not satisfy that requirement.';
                        } else {
                            $text .= ' -> your PHP version ('.$package->getPrettyVersion().') does not satisfy that requirement.';
                        }

                        return $text;
                    }

                    if (0 === strpos($targetName, 'ext-')) {
                        // handle php extensions
                        $ext = substr($targetName, 4);
                        $error = extension_loaded($ext) ? 'has the wrong version ('.(phpversion($ext) ?: '0').') installed' : 'is missing from your system';

                        return $text . ' -> the requested PHP extension '.$ext.' '.$error.'.';
                    }

                    if (0 === strpos($targetName, 'lib-')) {
                        // handle linked libs
                        $lib = substr($targetName, 4);

                        return $text . ' -> the requested linked library '.$lib.' has the wrong version installed or is missing from your system, make sure to have the extension providing it.';
                    }

                    if ($providers = $pool->whatProvides($targetName, $this->reasonData->getConstraint(), true, true)) {
                        return $text . ' -> satisfiable by ' . $this->formatPackagesUnique($pool, $providers) .' but these conflict with your requirements or minimum-stability.';
                    }

                    return $text . ' -> no matching package found.';
                }

                return $text;

            case self::RULE_PACKAGE_OBSOLETES:
                return $ruleText;
            case self::RULE_INSTALLED_PACKAGE_OBSOLETES:
                return $ruleText;
            case self::RULE_PACKAGE_SAME_NAME:
                return 'Can only install one of: ' . $this->formatPackagesUnique($pool, $this->getLiterals()) . '.';
            case self::RULE_PACKAGE_IMPLICIT_OBSOLETES:
                return $ruleText;
            case self::RULE_LEARNED:
                return 'Conclusion: '.$ruleText;
            case self::RULE_PACKAGE_ALIAS:
                return $ruleText;
            default:
                return '('.$ruleText.')';
        }
    }

    protected function formatPackagesUnique($pool, array $packages)
    {
        $prepared = array();
        foreach ($packages as $package) {
            if (!is_object($package)) {
                $package = $pool->literalToPackage($package);
            }
            $prepared[$package->getName()]['name'] = $package->getPrettyName();
            $prepared[$package->getName()]['versions'][$package->getVersion()] = $package->getPrettyVersion();
        }
        foreach ($prepared as $name => $package) {
            $prepared[$name] = $package['name'].'['.implode(', ', $package['versions']).']';
        }

        return implode(', ', $prepared);
    }

    /**
     * Formats a rule as a string of the format (Literal1|Literal2|...)
     *
     * @return string
     */
    public function __toString()
    {
        $result = ($this->isDisabled()) ? 'disabled(' : '(';

        $result .= $this->literal1 . '|' . $this->literal2 . ')';

        return $result;
    }
}
