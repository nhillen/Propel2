<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license MIT License
 */

namespace Propel\Generator\Builder\Om;

use Propel\Generator\Builder\DataModelBuilder;
use Propel\Generator\Builder\Util\PropelTemplate;
use Propel\Generator\Exception\InvalidArgumentException;
use Propel\Generator\Exception\LogicException;
use Propel\Generator\Exception\RuntimeException;
use Propel\Generator\Model\Column;
use Propel\Generator\Model\ForeignKey;
use Propel\Generator\Model\Table;

/**
 * Baseclass for OM-building classes.
 *
 * OM-building classes are those that build a PHP (or other) class to service
 * a single table.  This includes Peer classes, Entity classes, Map classes,
 * Node classes, Nested Set classes, etc.
 *
 * @author Hans Lellelid <hans@xmpl.org>
 */
abstract class AbstractOMBuilder extends DataModelBuilder
{
    /**
     * Declared fully qualified classnames, to build the 'namespace' statements
     * according to this table's namespace.
     *
     * @var array
     */
    protected $declaredClasses = array();

    /**
     * Mapping between fully qualified classnames and their short classname or alias
     *
     * @var array
     */
    protected $declaredShortClassesOrAlias = array();

    /**
     * List of classes that can be use without alias when model don't have namespace
     *
     * @var array
     */
    protected $whiteListOfDeclaredClasses = array('PDO', 'Exception', 'DateTime');

    /**
     * Builds the PHP source for current class and returns it as a string.
     *
     * This is the main entry point and defines a basic structure that classes should follow.
     * In most cases this method will not need to be overridden by subclasses.  This method
     * does assume that the output language is PHP code, so it will need to be overridden if
     * this is not the case.
     *
     * @return string The resulting PHP sourcecode.
     */
    public function build()
    {
        $this->validateModel();
        $this->declareClass($this->getFullyQualifiedClassName());

        $script = '';
        $this->addClassOpen($script);
        $this->addClassBody($script);
        $this->addClassClose($script);

        $ignoredNamespace = $this->getNamespace();

        if ($useStatements = $this->getUseStatements($ignoredNamespace ?: 'namespace')) {
            $script = $useStatements . $script;
        }

        if ($namespaceStatement = $this->getNamespaceStatement()) {
            $script = $namespaceStatement . $script;
        }

        $script =  "<?php

" . $script;

        return $this->clean($script);
    }

    /**
     * Validates the current table to make sure that it won't
     * result in generated code that will not parse.
     *
     * This method may emit warnings for code which may cause problems
     * and will throw exceptions for errors that will definitely cause
     * problems.
     */
    protected function validateModel()
    {
        // Validation is currently only implemented in the subclasses.
    }

    /**
     * Creates a $obj = new Book(); code snippet. Can be used by frameworks, for instance, to
     * extend this behavior, e.g. initialize the object after creating the instance or so.
     *
     * @return string Some code
     */
    public function buildObjectInstanceCreationCode($objName, $clsName)
    {
        return "$objName = new $clsName();";
    }

    /**
     * Returns the qualified (prefixed) classname that is being built by the current class.
     * This method must be implemented by child classes.
     *
     * @return string
     */
    abstract public function getUnprefixedClassName();

    /**
     * Returns the unqualified classname (e.g. Book)
     *
     * @return string
     */
    public function getUnqualifiedClassName()
    {
        return $this->getUnprefixedClassName();
    }

    /**
     * Returns the qualified classname (e.g. Model\Book)
     *
     * @return string
     */
    public function getQualifiedClassName()
    {
        if ($namespace = $this->getNamespace()) {
            return $namespace . '\\' . $this->getUnqualifiedClassName();
        }

        return $this->getUnqualifiedClassName();
    }

    /**
     * Returns the fully qualified classname (e.g. \Model\Book)
     *
     * @return string
     */
    public function getFullyQualifiedClassName()
    {
        return '\\' . $this->getQualifiedClassName();
    }
    /**
     * Returns FQCN alias of getFullyQualifiedClassName
     *
     * @return string
     */
    public function getClassName()
    {
        return $this->getFullyQualifiedClassName();
    }

    /**
     * Gets the dot-path representation of current class being built.
     *
     * @return string
     */
    public function getClasspath()
    {
        if ($this->getPackage()) {
            return $this->getPackage() . '.' . $this->getUnqualifiedClassName();
        }

        return $this->getUnqualifiedClassName();
    }

    /**
     * Gets the full path to the file for the current class.
     *
     * @return string
     */
    public function getClassFilePath()
    {
        return ClassTools::createFilePath($this->getPackagePath(), $this->getUnqualifiedClassName());
    }

    /**
     * Gets package name for this table.
     * This is overridden by child classes that have different packages.
     * @return string
     */
    public function getPackage()
    {
        $pkg = ($this->getTable()->getPackage() ? $this->getTable()->getPackage() : $this->getDatabase()->getPackage());
        if (!$pkg) {
            $pkg = $this->getBuildProperty('targetPackage');
        }

        return $pkg;
    }

    /**
     * Returns filesystem path for current package.
     * @return string
     */
    public function getPackagePath()
    {
        $pkg = $this->getPackage();

        if (false !== strpos($pkg, '/')) {
            $pkg = preg_replace('#\.(map|om)$#', '/\1', $pkg);
            $pkg = preg_replace('#\.(Map|Om)$#', '/\1', $pkg);

            return $pkg;
        }

        return strtr($pkg, '.', '/');
    }

    /**
     * Returns the user-defined namespace for this table,
     * or the database namespace otherwise.
     *
     * @return string
     */
    public function getNamespace()
    {
        return $this->getTable()->getNamespace();
    }

    /**
     * This declare the class use and get the correct name to use (short classname, Alias, or FQCN)
     *
     * @param  AbstractOMBuilder $builder
     * @param  boolean           $fqcn    true to return the $fqcn classname
     * @return string            ClassName, Alias or FQCN
     */
    public function getClassNameFromBuilder($builder, $fqcn = false)
    {
        if ($fqcn) {
            return $builder->getFullyQualifiedClassName();
        }

        $namespace = $builder->getNamespace();
        $class = $builder->getUnqualifiedClassName();

        if (isset($this->declaredClasses[$namespace])
            && isset($this->declaredClasses[$namespace][$class])) {
            return $this->declaredClasses[$namespace][$class];
        }

        return $this->declareClassNamespace($class, $namespace, true);
    }

    /**
     * Declare a class to be use and return it's name or it's alias
     *
     * @param  string         $class     the class name
     * @param  string         $namespace the namespace
     * @param  string|boolean $alias     the alias wanted, if set to True, it automatically adds an alias when needed
     * @return string         the class name or it's alias
     */
    public function declareClassNamespace($class, $namespace = '', $alias = false)
    {
        //check if the class is already declared
        if (isset($this->declaredClasses[$namespace])
            && isset($this->declaredClasses[$namespace][$class])) {
            return $this->declaredClasses[$namespace][$class];
        }

        $forcedAlias = $this->needAliasForClassName($class, $namespace);

        if (false === $alias || true === $alias || null === $alias) {
            $aliasWanted = $class;
            $alias = $alias || $forcedAlias;
        } else {
            $aliasWanted = $alias;
            $forcedAlias = false;
        }

        if (!$forcedAlias && !isset($this->declaredShortClassesOrAlias[$aliasWanted])) {
            if (!isset($this->declaredClasses[$namespace])) {
                $this->declaredClasses[$namespace] = array();
            }

            $this->declaredClasses[$namespace][$class] = $aliasWanted;
            $this->declaredShortClassesOrAlias[$aliasWanted] = $namespace . '\\' . $class;

            return $aliasWanted;
        }

        // we have a duplicate class and asked for an automatic Alias
        if (false !== $alias) {
            if ('\\Base' == substr($namespace, -5) || 'Base' == $namespace) {
                return $this->declareClassNamespace($class, $namespace, 'Base' . $class);
            }

            return $this->declareClassNamespace($class, $namespace, 'Child' . $class);
        }

        throw new LogicException(sprintf(
            'The class %s duplicates the class %s and can\'t be used without alias',
            $namespace . '\\' . $class,
            $this->declaredShortClassesOrAlias[$aliasWanted]
        ));
    }

    /**
     * check if the current $class need an alias or if the class could be used with a shortname without conflict
     * @param string $class
     * @param string $namespace
     */
    protected function needAliasForClassName($class, $namespace)
    {
        if ($namespace == $this->getNamespace()) {
            return false;
        }

        if (str_replace('\\Base', '', $namespace) == str_replace('\\Base', '', $this->getNamespace())) {
            return true;
        }

        if (empty($namespace) && 'Base' === $this->getNamespace()) {
            if (str_replace(array('Peer', 'Query'), '', $class) == str_replace(array('Peer', 'Query'), '', $this->getUnqualifiedClassName())) {
                return true;
            }

            if ((false !== strpos($class, 'Peer') || false !== strpos($class, 'Query'))) {
                return true;
            }

            // force alias for model without namespace
            if (false === array_search($class, $this->whiteListOfDeclaredClasses, true)) {
                return true;
            }
        }

        if ('Base' === $namespace && '' === $this->getNamespace()) {
            // force alias for model without namespace
            if (false === array_search($class, $this->whiteListOfDeclaredClasses, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Declare a use statement for a $class with a $namespace and an $aliasPrefix
     * This return the short ClassName or an alias
     *
     * @param  string $class       the class
     * @param  string $namespace   the namespace
     * @param  mixed  $aliasPrefix optionally an alias or True to force an automatic alias prefix (Base or Child)
     * @return string the short ClassName or an alias
     */
    public function declareClassNamespacePrefix($class, $namespace = '', $aliasPrefix = false)
    {
        if (false !== $aliasPrefix && true !== $aliasPrefix) {
            $alias = $aliasPrefix . $class;
        } else {
            $alias = $aliasPrefix;
        }

        return $this->declareClassNamespace($class, $namespace, $alias);
    }

    /**
     * Declare a Fully qualified classname with an $aliasPrefix
     * This return the short ClassName to use or an alias
     *
     * @param  string $fullyQualifiedClassName the fully qualified classname
     * @param  mixed  $aliasPrefix             optionally an alias or True to force an automatic alias prefix (Base or Child)
     * @return string the short ClassName or an alias
     */
    public function declareClass($fullyQualifiedClassName, $aliasPrefix = false)
    {
        $fullyQualifiedClassName = trim($fullyQualifiedClassName, '\\');
        if (($pos = strrpos($fullyQualifiedClassName, '\\')) !== false) {
            return $this->declareClassNamespacePrefix(substr($fullyQualifiedClassName, $pos + 1), substr($fullyQualifiedClassName, 0, $pos), $aliasPrefix);
        }
        // root namespace
        return $this->declareClassNamespacePrefix($fullyQualifiedClassName, '', $aliasPrefix);
    }

    /**
     * @param $builder
     * @param boolean|string $aliasPrefix the prefix for the Alias or True for auto generation of the Alias
     */
    public function declareClassFromBuilder($builder, $aliasPrefix = false)
    {
        return $this->declareClassNamespacePrefix($builder->getUnqualifiedClassName(), $builder->getNamespace(), $aliasPrefix);
    }

    public function declareClasses()
    {
        $args = func_get_args();
        foreach ($args as $class) {
            $this->declareClass($class);
        }
    }

    /**
     * Get the list of declared classes for a given $namespace or all declared classes
     *
     * @param  string $namespace the namespace or null
     * @return array  list of declared classes
     */
    public function getDeclaredClasses($namespace = null)
    {
        if (null !== $namespace && isset($this->declaredClasses[$namespace])) {
            return $this->declaredClasses[$namespace];
        }

        return $this->declaredClasses;
    }

    /**
     * return the string for the class namespace
     *
     * @return string
     */
    public function getNamespaceStatement()
    {
        $namespace = $this->getNamespace();
        if (!empty($namespace)) {
            return sprintf("namespace %s;

", $namespace);
        }
    }

    /**
     * Return all the use statement of the class
     *
     * @param  string $ignoredNamespace the ignored namespace
     * @return string
     */
    public function getUseStatements($ignoredNamespace = null)
    {
        $script = '';
        $declaredClasses = $this->declaredClasses;
        unset($declaredClasses[$ignoredNamespace]);
        ksort($declaredClasses);
        foreach ($declaredClasses as $namespace => $classes) {
            asort($classes);
            foreach ($classes as $class => $alias) {
                //Don't use our own class
                if ($class == $this->getUnqualifiedClassName() && $namespace == $this->getNamespace()) {
                    continue;
                }
                if ($class == $alias) {
                    $script .= sprintf("use %s\\%s;
", $namespace, $class);
                } else {
                    $script .= sprintf("use %s\\%s as %s;
", $namespace, $class, $alias);
                }
            }
        }

        return $script;
    }

    /**
     * Shortcut method to return the [stub] peer classname for current table.
     * This is the classname that is used whenever object or peer classes want
     * to invoke methods of the peer classes.
     * @param  boolean $fqcn
     * @return string  (e.g. 'MyPeer')
     */
    public function getPeerClassName($fqcn = false)
    {
        return $this->getClassNameFromBuilder($this->getStubPeerBuilder(), $fqcn);
    }

    /**
     * Shortcut method to return the [stub] query classname for current table.
     * This is the classname that is used whenever object or peer classes want
     * to invoke methods of the query classes.
     * @param  boolean $fqcn
     * @return string  (e.g. 'Myquery')
     */
    public function getQueryClassName($fqcn = false)
    {
        return $this->getClassNameFromBuilder($this->getStubQueryBuilder(), $fqcn);
    }

    /**
     * Returns the object classname for current table.
     * This is the classname that is used whenever object or peer classes want
     * to invoke methods of the object classes.
     * @param  boolean $fqcn
     * @return string  (e.g. 'My')
     */
    public function getObjectClassName($fqcn = false)
    {
        return $this->getClassNameFromBuilder($this->getStubObjectBuilder(), $fqcn);
    }

    /**
     * Get the column constant name (e.g. PeerName::COLUMN_NAME).
     *
     * @param Column $col       The column we need a name for.
     * @param string $classname The Peer classname to use.
     *
     * @return string If $classname is provided, then will return $classname::COLUMN_NAME; if not, then the peername is looked up for current table to yield $currTablePeer::COLUMN_NAME.
     */
    public function getColumnConstant($col, $classname = null)
    {
        if (null === $col) {
            throw new InvalidArgumentException('No columns were specified.');
        }

        if (null === $classname) {
            return $this->getBuildProperty('classPrefix') . $col->getConstantName();
        }

        // was it overridden in schema.xml ?
        if ($col->getPeerName()) {
            $const = strtoupper($col->getPeerName());
        } else {
            $const = strtoupper($col->getName());
        }

        return $classname.'::'.$const;
    }

    /**
     * Gets the basePeer path if specified for table/db.
     * If not, will return 'propel.util.BasePeer'
     * @return string
     */
    public function getBasePeer(Table $table)
    {
        $class = $table->getBasePeer();
        if (null === $class) {
            $class = 'propel.util.BasePeer';
        }

        return $class;
    }

    /**
     * Convenience method to get the foreign Table object for an fkey.
     * @deprecated use ForeignKey::getForeignTable() instead
     * @return Table
     */
    protected function getForeignTable(ForeignKey $fk)
    {
        return $this->getTable()->getDatabase()->getTable($fk->getForeignTableName());
    }

    /**
     * Convenience method to get the default Join Type for a relation.
     * If the key is required, an INNER JOIN will be returned, else a LEFT JOIN will be suggested,
     * unless the schema is provided with the DefaultJoin attribute, which overrules the default Join Type
     *
     * @param  ForeignKey $fk
     * @return string
     */
    protected function getJoinType(ForeignKey $fk)
    {
        if ($defaultJoin = $fk->getDefaultJoin()) {
            return "'" . $defaultJoin . "'";
        }

        if ($fk->isLocalColumnsRequired()) {
            return 'Criteria::INNER_JOIN';
        }

        return 'Criteria::LEFT_JOIN';
    }

    /**
     * Gets the PHP method name affix to be used for fkeys for the current table (not referrers to this table).
     *
     * The difference between this method and the getRefFKPhpNameAffix() method is that in this method the
     * classname in the affix is the foreign table classname.
     *
     * @param  ForeignKey $fk     The local FK that we need a name for.
     * @param  boolean    $plural Whether the php name should be plural (e.g. initRelatedObjs() vs. addRelatedObj()
     * @return string
     */
    public function getFKPhpNameAffix(ForeignKey $fk, $plural = false)
    {
        if ($fk->getPhpName()) {
            if ($plural) {
                return $this->getPluralizer()->getPluralForm($fk->getPhpName());
            }

            return $fk->getPhpName();
        }

        $className = $fk->getForeignTable()->getPhpName();
        if ($plural) {
            $className = $this->getPluralizer()->getPluralForm($className);
        }

        return $className . $this->getRelatedBySuffix($fk);
    }

    /**
     * Gets the "RelatedBy*" suffix (if needed) that is attached to method and variable names.
     *
     * The related by suffix is based on the local columns of the foreign key.  If there is more than
     * one column in a table that points to the same foreign table, then a 'RelatedByLocalColName' suffix
     * will be appended.
     *
     * @return string
     */
    protected static function getRelatedBySuffix(ForeignKey $fk)
    {
        $relCol = '';
        foreach ($fk->getLocalForeignMapping() as $localColumnName => $foreignColumnName) {
            $localTable  = $fk->getTable();
            $localColumn = $localTable->getColumn($localColumnName);
            if (!$localColumn) {
                throw new RuntimeException(sprintf('Could not fetch column: %s in table %s.', $localColumnName, $localTable->getName()));
            }

            if (count($localTable->getForeignKeysReferencingTable($fk->getForeignTableName())) > 1
             || count($fk->getForeignTable()->getForeignKeysReferencingTable($fk->getTableName())) > 0
             || $fk->getForeignTableName() == $fk->getTableName()) {
                // self referential foreign key, or several foreign keys to the same table, or cross-reference fkey
                $relCol .= $localColumn->getPhpName();
            }
        }

        if (!empty($relCol)) {
            $relCol = 'RelatedBy' . $relCol;
        }

        return $relCol;
    }

    /**
     * Gets the PHP method name affix to be used for referencing foreign key methods and variable names (e.g. set????(), $coll???).
     *
     * The difference between this method and the getFKPhpNameAffix() method is that in this method the
     * classname in the affix is the classname of the local fkey table.
     *
     * @param  ForeignKey $fk     The referrer FK that we need a name for.
     * @param  boolean    $plural Whether the php name should be plural (e.g. initRelatedObjs() vs. addRelatedObj()
     * @return string
     */
    public function getRefFKPhpNameAffix(ForeignKey $fk, $plural = false)
    {
        $pluralizer = $this->getPluralizer();
        if ($fk->getRefPhpName()) {
            return $plural ? $pluralizer->getPluralForm($fk->getRefPhpName()) : $fk->getRefPhpName();
        }

        $className = $fk->getTable()->getPhpName();
        if ($plural) {
            $className = $pluralizer->getPluralForm($className);
        }

        return $className . $this->getRefRelatedBySuffix($fk);
    }

    protected static function getRefRelatedBySuffix(ForeignKey $fk)
    {
        $relCol = '';
        foreach ($fk->getLocalForeignMapping() as $localColumnName => $foreignColumnName) {
            $localTable = $fk->getTable();
            $localColumn = $localTable->getColumn($localColumnName);
            if (!$localColumn) {
                throw new RuntimeException(sprintf('Could not fetch column: %s in table %s.', $localColumnName, $localTable->getName()));
            }
            $foreignKeysToForeignTable = $localTable->getForeignKeysReferencingTable($fk->getForeignTableName());
            if ($fk->getForeignTableName() == $fk->getTableName()) {
                // self referential foreign key
                $relCol .= $fk->getForeignTable()->getColumn($foreignColumnName)->getPhpName();
                if (count($foreignKeysToForeignTable) > 1) {
                    // several self-referential foreign keys
                    $relCol .= array_search($fk, $foreignKeysToForeignTable);
                }
            } elseif (count($foreignKeysToForeignTable) > 1 || count($fk->getForeignTable()->getForeignKeysReferencingTable($fk->getTableName())) > 0) {
                // several foreign keys to the same table, or symmetrical foreign key in foreign table
                $relCol .= $localColumn->getPhpName();
            }
        }

        if (!empty($relCol)) {
            $relCol = 'RelatedBy' . $relCol;
        }

        return $relCol;
    }

    /**
     * Checks whether any registered behavior on that table has a modifier for a hook
     * @param  string  $hookName The name of the hook as called from one of this class methods, e.g. "preSave"
     * @param  string  $modifier The name of the modifier object providing the method in the behavior
     * @return boolean
     */
    public function hasBehaviorModifier($hookName, $modifier)
    {
        $modifierGetter = 'get' . $modifier;
        foreach ($this->getTable()->getBehaviors() as $behavior) {
            if (method_exists($behavior->$modifierGetter(), $hookName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether any registered behavior on that table has a modifier for a hook
     * @param string $hookName The name of the hook as called from one of this class methods, e.g. "preSave"
     * @param string $modifier The name of the modifier object providing the method in the behavior
     * @param string &$script The script will be modified in this method.
     */
    public function applyBehaviorModifierBase($hookName, $modifier, &$script, $tab = "        ")
    {
        $modifierGetter = 'get' . $modifier;
        foreach ($this->getTable()->getBehaviors() as $behavior) {
            $modifier = $behavior->$modifierGetter();
            if (method_exists($modifier, $hookName)) {
                if (strpos($hookName, 'Filter') !== false) {
                    // filter hook: the script string will be modified by the behavior
                    $modifier->$hookName($script, $this);
                } else {
                    // regular hook: the behavior returns a string to append to the script string
                    if (!$addedScript = $modifier->$hookName($this)) {
                        continue;
                    }
                    $script .= "
" . $tab . '// ' . $behavior->getName() . " behavior
";
                    $script .= preg_replace('/^/m', $tab, $addedScript);
                }
            }
        }
    }

    /**
     * Checks whether any registered behavior content creator on that table exists a contentName
     * @param string $contentName The name of the content as called from one of this class methods, e.g. "parentClassName"
     * @param string $modifier    The name of the modifier object providing the method in the behavior
     */
    public function getBehaviorContentBase($contentName, $modifier)
    {
        $modifierGetter = 'get' . $modifier;
        foreach ($this->getTable()->getBehaviors() as $behavior) {
            $modifier = $behavior->$modifierGetter();
            if (method_exists($modifier, $contentName)) {
                return $modifier->$contentName($this);
            }
        }
    }

    /**
     * Use Propel simple templating system to render a PHP file using variables
     * passed as arguments. The template file name is relative to the behavior's
     * directory name.
     *
     * @param  string $filename
     * @param  array  $vars
     * @param  string $templateDir
     * @return string
     */
    public function renderTemplate($filename, $vars = array(), $templateDir = '/templates/')
    {
        $filePath = __DIR__ . $templateDir . $filename;
        if (!file_exists($filePath)) {
            // try with '.php' at the end
            $filePath = $filePath . '.php';
            if (!file_exists($filePath)) {
                throw new \InvalidArgumentException(sprintf('Template "%s" not found in "%s" directory', $filename, __DIR__ . $templateDir));
            }
        }
        $template = new PropelTemplate();
        $template->setTemplateFile($filePath);
        $vars = array_merge($vars, array('behavior' => $this));

        return $template->render($vars);
    }

    /**
     * Most of the code comes from the PHP-CS-Fixer project
     */
    private function clean($content)
    {
        // trailing whitespaces
        $content = preg_replace('/[ \t]*$/m', '', $content);

        // indentation
        $content = preg_replace_callback('/^([ \t]+)/m', function ($matches) use ($content) {
            return str_replace("\t", '    ', $matches[0]);
        }, $content);

        // line feed
        $content = str_replace("\r\n", "\n", $content);

        // Unused "use" statements
        preg_match_all('/^use (?P<class>[^\s;]+)(?:\s+as\s+(?P<alias>.*))?;/m', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (isset($match['alias'])) {
                $short = $match['alias'];
            } else {
                $parts = explode('\\', $match['class']);
                $short = array_pop($parts);
            }

            preg_match_all('/\b'.$short.'\b/i', str_replace($match[0]."\n", '', $content), $m);
            if (!count($m[0])) {
                $content = str_replace($match[0]."\n", '', $content);
            }
        }

        // end of line
        if (strlen($content) && "\n" != substr($content, -1)) {
            $content = $content."\n";
        }

        return $content;
    }
}
