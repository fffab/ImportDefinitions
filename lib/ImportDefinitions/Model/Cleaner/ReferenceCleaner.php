<?php
/**
 * Import Definitions.
 *
 * LICENSE
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  Copyright (c) 2016-2017 W-Vision (http://www.w-vision.ch)
 * @license    https://github.com/w-vision/ImportDefinitions/blob/master/gpl-3.0.txt GNU General Public License version 3 (GPLv3)
 */

namespace ImportDefinitions\Model\Cleaner;

use ImportDefinitions\Model\Definition;
use Pimcore\Model\Dependency;
use Pimcore\Model\Object\Concrete;

/**
 * Class AbstractCleaner
 * @package ImportDefinitions\Model\Cleaner
 */
class ReferenceCleaner extends AbstractCleaner
{

    /**
     *
     * @param Definition $definition
     * @param Concrete[] $objects
     * @return mixed
     */
    public function cleanup($definition, $objects)
    {
        $notFoundObjects = $this->getObjectsToClean($definition, $objects);

        foreach ($notFoundObjects as $obj) {
            $dependency = $obj->getDependencies();

            if ($dependency instanceof Dependency) {
                if (count($dependency->getRequiredBy()) === 0) {
                    $obj->delete();
                } else {
                    $obj->setPublished(false);
                    $obj->save();
                }
            }
        }
    }
}
