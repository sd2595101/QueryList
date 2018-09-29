<?php
/**
 * Created by PhpStorm.
 * User: Jaeger <JaegerCode@gmail.com>
 * Date: 2017/9/21
 */
namespace QL\Dom;

use Illuminate\Support\Collection;
use phpQuery;
use QL\QueryList;
use Closure;
use phpQueryObject;

class Query
{
    protected $html;
    protected $document;
    protected $rules;
    protected $range = null;
    protected $ql;

    /**
     * @var Collection
     */
    protected $data;

    public function __construct(QueryList $ql)
    {
        $this->ql = $ql;
    }

    /**
     * @return mixed
     */
    public function getHtml()
    {
        return $this->html;
    }

    /**
     * @param $html
     * @param null $charset
     * @return QueryList
     */
    public function setHtml($html, $charset = null)
    {
        $this->html = value($html);
        $this->document = phpQuery::newDocumentHTML($this->html, $charset);
        return $this->ql;
    }

    /**
     * Get crawl results
     *
     * @param Closure|null $callback
     * @return Collection|static
     */
    public function getData(Closure $callback = null)
    {
        return is_null($callback) ? $this->data : $this->data->map($callback);
    }

    /**
     * @param Collection $data
     */
    public function setData(Collection $data)
    {
        $this->data = $data;
    }

    /**
     * Searches for all elements that match the specified expression.
     *
     * @param $selector A string containing a selector expression to match elements against.
     * @return Elements
     */
    public function find($selector)
    {
        return (new Dom($this->document))->find($selector);
    }

    /**
     * Set crawl rule
     *
     * $rules = [
     *    'rule_name1' => ['selector','HTML attribute | text | html','Tag filter list','callback'],
     *    'rule_name2' => ['selector','HTML attribute | text | html','Tag filter list','callback'],
     *    // ...
     *  ]
     *
     * @param array $rules
     * @return QueryList
     */
    public function rules(array $rules)
    {
        $this->rules = $rules;
        return $this->ql;
    }

    /**
     * Set the slice area for crawl list
     *
     * @param $selector
     * @return QueryList
     */
    public function range($selector)
    {
        $this->range = $selector;
        return $this->ql;
    }

    /**
     * Remove HTML head,try to solve the garbled
     *
     * @return QueryList
     */
    public function removeHead()
    {
        $html = preg_replace('/<head.+?>.+<\/head>/is', '<head></head>', $this->html);
        $this->setHtml($html);
        return $this->ql;
    }

    /**
     * Execute the query rule
     *
     * @param Closure|null $callback
     * @return QueryList
     */
    public function query(Closure $callback = null)
    {
        $this->data = $this->getList();
        $callback && $this->data = $this->data->map($callback);
        return $this->ql;
    }

    protected function getList()
    {
        if (!empty($this->range)) {
            return $this->getListUseRange();
        } else {
            return $this->getListNoRange();
        }
    }

    protected function getListUseRange()
    {
        $data = [];
        $robj = $this->document->find($this->range);
        $i = 0;
        foreach ($robj as $item) {
            foreach ($this->rules as $key => $rule) {
                $tags = $rule[ 2 ] ?? '';
                $iobj = pq($item, $this->document)->find($rule[ 0 ]);
                $data[ $i ][ $key ] = $this->findElems($rule[ 1 ], $tags, $iobj);
                if (isset($rule[ 3 ])) {
                    $data[ $i ][ $key ] = call_user_func($rule[ 3 ], $data[ $i ][ $key ], $key);
                }
            }
            $i++;
        }
        return collect($data);
    }

    /**
     * find data by rules, not specific ranges.
     */
    protected function getListNoRange()
    {
        $data = [];
        foreach ($this->rules as $ruleName => $rule) {
            $finded = $this->document->find($rule[ 0 ]);
            $data[ $ruleName ] = $this->buildOneRule($rule, $finded, $ruleName);
        }
        return collect(array($data));
    }

    protected function buildOneRule($rule, $finded, $ruleName)
    {
        $attr = $rule[ 1 ] ?? '*';
        $tags = $rule[ 2 ] ?? '';
        $callback = $rule[ 3 ] ?? false;
        $result = $this->findElementAttrOne($attr, $tags, pq($finded));
        if (!is_callable($callback)) {
            return $result;
        }
        return call_user_func($rule[ 3 ], $result, $ruleName);
    }

    protected function findElementAttrOne($attr, $tags, phpQueryObject $pq)
    {
        //dump(get_class($pq));
        switch ($attr) {
            case 'text':
                return $this->allowTags($pq->html(), $tags);
            case 'texts':
                return \array_map(function($e) use ($tags) {
                    return $this->allowTags(pq($e)->html(), $tags);
                }, $pq->elements);
            case 'html':
                return $this->stripTags($pq->html(), $tags);
            case 'exists':
                return \count($pq->elements) > 0;
            default:
                return $pq->attr($attr);
        }
    }

    protected function findElems($attrs, $tags, phpQueryObject $pq)
    {
        if (is_array($attrs) || '/r' == substr($attrs, -2) || count($pq->elements) > 1) {
            return array_map(function($elem) use ($attrs, $tags) {
                return $this->findSingleElemAttrs($attrs, $tags, pq($elem));
            }, $pq->elements);
        } else {
            return $this->findSingleElemAttrs($attrs, $tags, $pq);
        }
    }

    protected function findSingleElemAttrs($attrs, $tags, phpQueryObject $pq)
    {
        $data = [];
        if (is_array($attrs)) {
            foreach ($attrs as $key => $attr) {
                $data[ $key ] = $this->findElementAttrOne($attr, $tags, $pq);
            }
            unset($key, $attr);
        } else {
            $data = $this->findElementAttrOne($attrs, $tags, $pq);
        }
        return $data;
    }
    
    /**
     * 去除特定的html标签
     * @param  string $html
     * @param  string $tags_str 多个标签名之间用空格隔开
     * @return string
     */
    protected function stripTags($html, $tags_str)
    {
        $tagsArr = $this->tag($tags_str);
        $html = $this->removeTags($html, $tagsArr[ 1 ]);
        $p = array();
        foreach ($tagsArr[ 0 ] as $tag) {
            $p[] = "/(<(?:\/" . $tag . "|" . $tag . ")[^>]*>)/i";
        }
        $html = preg_replace($p, "", trim($html));
        return $html;
    }

    /**
     * 保留特定的html标签
     * @param  string $html
     * @param  string $tags_str 多个标签名之间用空格隔开
     * @return string
     */
    protected function allowTags($html, $tags_str)
    {
        $tagsArr = $this->tag($tags_str);
        $html = $this->removeTags($html, $tagsArr[ 1 ]);
        $allow = '';
        foreach ($tagsArr[ 0 ] as $tag) {
            $allow .= "<$tag> ";
        }
        return strip_tags(trim($html), $allow);
    }

    protected function tag($tags_str)
    {
        $tagArr = preg_split("/\s+/", $tags_str, -1, PREG_SPLIT_NO_EMPTY);
        $tags = array(array(), array());
        foreach ($tagArr as $tag) {
            if (preg_match('/-(.+)/', $tag, $arr)) {
                array_push($tags[ 1 ], $arr[ 1 ]);
            } else {
                array_push($tags[ 0 ], $tag);
            }
        }
        return $tags;
    }

    /**
     * 移除特定的html标签
     * @param  string $html
     * @param  array  $tags 标签数组
     * @return string
     */
    protected function removeTags($html, $tags)
    {
        $tag_str = '';
        if (count($tags)) {
            foreach ($tags as $tag) {
                $tag_str .= $tag_str ? ',' . $tag : $tag;
            }
            $doc = phpQuery::newDocumentHTML($html);
            pq($doc)->find($tag_str)->remove();
            $html = pq($doc)->htmlOuter();
            $doc->unloadDocument();
        }
        return $html;
    }
}
