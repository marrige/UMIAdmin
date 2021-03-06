<?php

namespace YM\Umi;

use Illuminate\Support\Facades\Config;
use YM\Models\Search;
use YM\Models\TableRelationOperation;
use YM\Models\UmiModel;
use YM\Facades\Umi as Ym;
use YM\umiAuth\Facades\umiAuth;
use YM\Umi\DataTable\DataType\DataTypeOperation;

class umiDataTableBuilder
{
    private $browser;
    private $read;
    private $edit;
    private $add;
    private $delete;
    private $buttonStyle;
    private $tableName;

    #按钮样式 style of button
    protected $BtnCssDelete = 'btn btn-sm btn-danger';
    protected $BtnCssNew = 'btn btn-sm btn-success';
    protected $BtnCssSmallEdit = 'btn btn-xs btn-info';
    protected $BtnCssSmallRead = 'btn btn-xs btn-warning';
    protected $BtnCssSmallDelete = 'btn btn-xs btn-danger';

    #默认数据表主键为id 可以通过继承修改
    #default data table's primary key is id, can be changed by inheriting this class
    protected $tableId = 'id';

    public function __construct()
    {
        $this->tableName = Ym::currentTableName();

        #获取所有BREAD 权限
        #get all bread authorization
        $permission = 'browser-' . $this->tableName;
        $this->browser = umiAuth::can($permission) ? true : false;

        $permission = 'read-' . $this->tableName;
        $this->read = umiAuth::can($permission) ? true : false;

        $permission = 'edit-' . $this->tableName;
        $this->edit = umiAuth::can($permission) ? true : false;

        $permission = 'add-' . $this->tableName;
        $this->add = umiAuth::can($permission) ? true : false;

        $permission = 'delete-' . $this->tableName;
        $this->delete = umiAuth::can($permission) ? true : false;

        #获取按钮没有被授权时候的样式,可以设置为不显示或者不可点击
        #get style of button when unauthorized, does not show or not editable
        $this->buttonStyle = Config::get('umi.unAuthorizedAccessStyle');
    }

    public function tableHeadAlert($superAdmin = false)
    {
        $html = <<<UMI
        <div class="alert alert-info">
			<button type="button" class="close" data-dismiss="alert">
				<i class="ace-icon fa fa-times"></i>
			</button>
			<strong>This is Table's head!</strong>
			This alert needs your attention, but it's not super important.
			<br />
		</div>
UMI;
        return $html;
    }

    public function tableHead($superAdmin = false)
    {
        #删除按钮 button of delete
        $buttonDelete = '';//$this->ButtonDelete($superAdmin);

        #新建按钮 button of new
        $buttonAdd = $this->ButtonAdd($superAdmin);

        $html = <<<UMI
        <p>
            $buttonDelete
            $buttonAdd
		</p>
UMI;
        return $html;
    }

    #这个专门为超级管理员定义的表格头, 可以继承此类, 自定义不同管理员的不同界面和功能
    #this is for super admin and this class can be extended for any specific
    #function or UI
    public function tableHeadSuperAdmin()
    {
        $buttonDelete = '';//$this->ButtonDelete(true);
        $buttonAdd = $this->ButtonAdd(true);

        $html = <<<UMI
        <p>
            $buttonDelete
            $buttonAdd
		</p>
UMI;
        return $html;
    }

    /**
     * @param bool $superAdmin
     * @return string
     */
    public function tableBody($superAdmin = false)
    {
        #是否有权限浏览表格数据
        #check if have authority to browser the data table
        if (!($superAdmin || $this->browser))
            return $this->wrongMessage('you are not authorized to browser this data table');

        #数据表头
        #table Head
        $dataTypeOp = new DataTypeOperation('browser', $this->tableName);
        $tHeads = $dataTypeOp->getTHead();
        if (!$tHeads->first())
            return $this->wrongMessage('Please open and set up field shows up function', '#');
        $tHeadHtml = '';
        foreach ($tHeads as $tHead) {
            $displayName = $tHead->display_name === '' ? $tHead->field : $tHead->display_name;
            $tHeadHtml .= "<th>$displayName</th>";
        }

        #数据表内容按照类型重写
        #table body will be rewrite according to the custom data type
        $fields = $dataTypeOp->getFields();
        $perPage = Config::get('umi.umi_table_perPage');
        $umiModel = new UmiModel($this->tableName);

        #获取数据
        #get data table
        $whereList = $this->getWhere();
        $dataSet = $umiModel->getSelectedTable($fields);

        #获取搜索结果分页参数
        #get the parameter of result of searching for paginate
        $whereLink = '';
        if (\Request::isMethod('post')){
            if ($whereList != null) {
                foreach ($whereList as $where) {
                    $value = $_REQUEST[$where->field . '-' . $where->id];
                    if ($value == '') continue;
                    if ($where->is_fuzzy) {
                        $whereLink .= "`$where->field` like '%$value%' and ";
                    } else {
                        $whereLink .= "`$where->field`='$value' and ";
                    }
                }
                $whereLink = $whereLink == '' ? '' : $whereLink . ' 1=1';
            }
        } else {
            if (isset($_REQUEST['w']))
                $whereLink = base64_decode($_REQUEST['w']);
        }

        #将参数添加到url接连 并生成新的数据
        #add parameter into url link and generate new data table
        $dataSet = $whereLink == '' ? $dataSet : $dataSet->whereRaw($whereLink);
        $dataSet = $dataSet->paginate($perPage);
        $args = $this->getArgs(['id', 'dd', 'dda', 'page']); //获取参数 get args
        if ($whereLink != '')
            $args['w'] = base64_encode($whereLink);
        $links = $dataSet->appends($args)->links();

        #获取用于执行数据库关联操作的数据
        #get data for execute data table relation operation
        $TRO = new TableRelationOperation();
        //$rules = $TRO->getRulesByNames(Ym::currentTableId(), false);
        $rules = $TRO->getTableRelationOperationByTableId(Ym::currentTableId());
        $activeFieldValueList = [];
        if ($rules) {
            foreach ($dataSet as $ds) {
                $activeFieldValue = '';
                foreach ($rules as $rule) {
                    $activeTableField = $rule->active_table_field;
                    $dsActField = $ds->$activeTableField;
                    $activeFieldValue .= "\"$activeTableField\":\"$dsActField\",";
                }
                #转换成对象类型
                #turn into object
                $activeFieldValue = $activeFieldValue == '' ? '' : "{" . trim($activeFieldValue,',') . "}";
                array_push($activeFieldValueList, $activeFieldValue);
            }
        }

        #是否开启 数据映射功能
        #if available for data reformat
        if (Config::get('umi.data_field_reformat'))
            $dataSet = $dataTypeOp->regulatedDataSet($dataSet);

        #数据表内容
        #table body
        $trBodyHtml = '';
        if ($dataSet) {
            $pointer = 0;
            foreach ($dataSet as $ds) {
                $trBodyHtml .= '<tr>';
                $trBodyHtml .= $this->checkboxHtml();
                foreach ($ds as $item => $value) {
                    $trBodyHtml .= '<td>';
                    $trBodyHtml .= $value;
                    $trBodyHtml .= '</td>';
                }

                #获取数据行的主键值
                #get value of primary key of record
                $primaryKey = Config::get('umi.primary_key');
                $recordId = array_has($ds, $primaryKey) ? $ds[$primaryKey] : 0;

                $activeFieldValue = $activeFieldValueList[$pointer];
                $trBodyHtml .= $this->breadButtonHtml($recordId, $superAdmin, $activeFieldValue);     //获取按钮 get button
                $trBodyHtml .= '</tr>';

                $pointer++;
            }
        }
        $html = <<<UMI
        <div class="row">
            <div class="col-xs-12">
                <table id="dynamic-table" class="table table-striped table-bordered table-hover">
                    <thead>
                        <tr>
                            <!--<th class="center">
                                <label class="pos-rel">
                                    <input type="checkbox" class="ace" />
                                    <span class="lbl"></span>
                                </label>
                            </th>-->
                            $tHeadHtml
                            <th></th>
                        </tr>
                    </thead>

                    <tbody>
                        $trBodyHtml
                    </tbody>
                </table>
            </div>
        </div>
UMI;
        $html .= $links;
        return $html;
    }

    public function getWhere()
    {
        if (\Request::isMethod('post')) {
            #获取search_tab_id        #get search_tab_id
            if (isset($_REQUEST['std'])) {
                $search = new Search();
                $searchList = $search->getSearchByTabId($_REQUEST['std'])->all();
                return $searchList;
            }
        }
    }

    public function tableFoot($superAdmin = false)
    {
        $html = <<< UMI
        <div class="alert alert-block alert-success">
            <button type="button" class="close" data-dismiss="alert">
                <i class="ace-icon fa fa-times"></i>
            </button>

            <p>
                <strong>
                    <i class="ace-icon fa fa-check"></i>
                    This is Table's Foot
                </strong>
                You can customize this footer.<br>
                Footer, Header, Body of Table can be different by extending a new class for the a new administrator
            </p>

            <p>
                <button class="btn btn-sm btn-success">Do This</button>
                <button class="btn btn-sm btn-info">Or This One</button>
            </p>
        </div>
UMI;
        return $html;
    }

    /**
     * @param $args - 获取url参数, 可以指定键值数组 get url args, can be array of key like ['id','key','search']
     * @return array - 参数的键值 the key
     */
    private function getArgs($args)
    {
        $args = is_array($args) ? $args : [$args];
        $arr = [];

        for ($i = 0; $i < count($args); $i++) {
            $key = $args[$i];
            $value = isset($_REQUEST[$key]) ? $_REQUEST[$key] : '';
            if ($value != '')
                $arr[$key] =  $value;
        }
        return $arr;
    }

    #region component
    private function checkboxHtml()
    {
        $disabled = $this->delete ? '' : 'disabled';
        $html = <<<UMI
        <td class="center">
        <label class="pos-rel">
            <input type="checkbox" class="ace" $disabled/>
            <span class="lbl"></span>
        </label>
        </td>
UMI;
        return '';
        //return $html;
    }

    private function breadButtonHtml($recordId, $superAdmin, $activeFieldValue)
    {
        #表格右侧小按钮
        #small button on the right side of table
        $buttonSmallEdit = $this->ButtonSmallEdit($recordId, $superAdmin);
        $buttonSmallRead = $this->ButtonSmallRead($recordId, $superAdmin);
        $buttonSmallDelete = $this->ButtonSmallDelete($recordId, $superAdmin, $activeFieldValue);
        $linkHideEdit = $this->LinkHideEdit($recordId, $superAdmin);
        $linkHideDelete = $this->LinkHideDelete($recordId, $superAdmin);
        $linkHideRead = $this->LinkHideRead($recordId, $superAdmin);

        $html = <<<UMI
        <td>
	    	<div class="hidden-sm hidden-xs btn-group">
	    		$buttonSmallEdit

	    		$buttonSmallRead

	    		$buttonSmallDelete
	    	</div>

	        <div class="hidden-md hidden-lg">
	    		<div class="inline pos-rel">
	    			<button class="btn btn-minier btn-primary dropdown-toggle" data-toggle="dropdown" data-position="auto">
	    				<i class="ace-icon fa fa-cog icon-only bigger-110"></i>
	    			</button>

	    			<ul class="dropdown-menu dropdown-only-icon dropdown-yellow dropdown-menu-right dropdown-caret dropdown-close">
	    				$linkHideRead

                        $linkHideEdit

                        $linkHideDelete
	    			</ul>
	    		</div>
	    	</div>
	    </td>
UMI;
        return $html;
    }

    private function ButtonAdd($superAdmin)
    {
        if ($superAdmin || $this->add) {
            return $this->ButtonAddHtml();
        } else {
            return $this->buttonStyle === 'disable' ?
                $this->ButtonAddHtml('disabled') : '';
        }
    }

    private function ButtonAddHtml($disable = '')
    {
        $html = <<<UMI
        <button class="$this->BtnCssNew $disable">
            <i class="ace-icon fa fa-plus"></i>
            New
        </button>
UMI;
        return $html;
    }

    private function ButtonDelete($superAdmin)
    {
        if ($superAdmin || $this->delete) {
            return $this->ButtonDeleteHtml();
        } else {
            return $this->buttonStyle === 'disable' ?
                $this->ButtonDeleteHtml('disabled') : '';
        }
    }

    private function ButtonDeleteHtml($disable = '')
    {
        $html = <<<UMI
        <button class="$this->BtnCssDelete $disable">
                    <i class="ace-icon fa fa-trash-o"></i>
            Delete
        </button>
UMI;
        return $html;
    }

    private function ButtonSmallEdit($recordId, $superAdmin)
    {
        if ($superAdmin || $this->edit) {
            return $this->ButtonSmallEditHtml($recordId);
        } else {
            return $this->buttonStyle === 'disable' ?
                $this->ButtonSmallEditHtml($recordId, 'disabled') : '';
        }
    }

    private function ButtonSmallEditHtml($recordId, $disable = '')
    {
        $html = <<<UMI
        <button class="$this->BtnCssSmallEdit $disable">
            <i class="ace-icon fa fa-pencil bigger-120"></i>
        </button>
UMI;
        return $html;
    }

    private function ButtonSmallRead($recordId, $superAdmin)
    {
        if ($superAdmin || $this->read) {
            return $this->ButtonSmallReadHtml($recordId);
        } else {
            return $this->buttonStyle === 'disable' ?
                $this->ButtonSmallReadHtml($recordId, 'disabled') : '';
        }
    }

    private function ButtonSmallReadHtml($recordId, $disable = '')
    {
        $html = <<<UMI
        <button class="$this->BtnCssSmallRead $disable">
            <i class="ace-icon fa fa-eye bigger-120"></i>
        </button>
UMI;
        return $html;
    }

    private function ButtonSmallDelete($recordId, $superAdmin, $activeFieldValue)
    {
        if ($superAdmin || $this->delete) {
            return $this->ButtonSmallDeleteHtml($recordId, $activeFieldValue);
        } else {
            return $this->buttonStyle === 'disable' ?
                $this->ButtonSmallDeleteHtml($recordId, $activeFieldValue, 'disabled') : '';
        }
    }

    private function ButtonSmallDeleteHtml($recordId, $activeFieldValue, $disable = '')
    {
        $activeFieldValue = base64_encode($activeFieldValue);
        $tableName = $this->tableName;//Ym::umiEncrypt($this->tableName);
        $html = <<<UMI
        <button class="$this->BtnCssSmallDelete $disable" $disable onclick="umiTableDelete('$tableName', '$recordId', '$activeFieldValue');">
            <i class="ace-icon fa fa-trash-o bigger-120"></i>
        </button>
UMI;
        return $html;
    }

    private function LinkHideRead($recordId, $superAdmin)
    {
        if ($superAdmin || $this->read) {
            return $this->LinkHideReadHtml($recordId);
        } else {
            return $this->buttonStyle === 'disable' ?
                $this->LinkHideReadHtml($recordId, 'disabled') : '';
        }
    }

    private function LinkHideReadHtml($recordId, $disable = '')
    {
        if ($disable === 'disabled') {
            $html = <<<UMI
            <li>
                <a href="#">
                    <span class="green" style="cursor:not-allowed">
                        <i class="ace-icon fa fa-eye bigger-120"></i>
                    </span>
                </a>
            </li>
UMI;
        } else {
            $html = <<<UMI
            <li>
                <a href="#" class="tooltip-info disabled" data-rel="tooltip" title="View">
                    <span class="green">
                        <i class="ace-icon fa fa-eye bigger-120"></i>
                    </span>
                </a>
            </li>
UMI;
        }
        return $html;
    }

    private function LinkHideEdit($recordId, $superAdmin)
    {
        if ($superAdmin || $this->edit) {
            return $this->LinkHideEditHtml($recordId);
        } else {
            return $this->buttonStyle === 'disable' ?
                $this->LinkHideEditHtml($recordId, 'disabled') : '';
        }
    }

    private function LinkHideEditHtml($recordId, $disable = '')
    {
        if ($disable === 'disabled') {
            $html = <<<UMI
            <li>
                <a href="#">
                    <span class="blue" style="cursor:not-allowed">
                        <i class="ace-icon fa fa-pencil-square-o bigger-120"></i>
                    </span>
                </a>
            </li>
UMI;
        } else {
            $html = <<<UMI
            <li>
                <a href="#" class="tooltip-success" data-rel="tooltip" title="Edit">
                    <span class="blue">
                        <i class="ace-icon fa fa-pencil-square-o bigger-120"></i>
                    </span>
                </a>
            </li>
UMI;
        }

        return $html;
    }

    private function LinkHideDelete($recordId, $superAdmin)
    {
        if ($superAdmin || $this->delete) {
            return $this->LinkHideDeleteHtml($recordId);
        } else {
            return $this->buttonStyle === 'disable' ?
                $this->LinkHideDeleteHtml($recordId, 'disabled') : '';
        }
    }

    private function LinkHideDeleteHtml($recordId, $disable = '')
    {
        if ($disable === 'disabled') {
            $html = <<<UMI
            <li>
                <a href="#">
                    <span class="red" style="cursor:not-allowed">
                        <i class="ace-icon fa fa-trash-o bigger-120"></i>
                    </span>
                </a>
            </li>
UMI;
        } else {
            $html = <<<UMI
            <li>
                <a href="#" class="tooltip-error" data-rel="tooltip" title="Delete">
                    <span class="red">
                        <i class="ace-icon fa fa-trash-o bigger-120"></i>
                    </span>
                </a>
            </li>
UMI;
        }
        return $html;
    }

    /**
     * 错误信息 the wrong message
     * @param $message
     * @param string $url - 错误信息中可以为按钮设置一个链接 到想要的页面
     *                    - set a url for redirection on the wrong message
     * @return string
     */
    private function wrongMessage($message, $url = '')
    {
        $showingButton = $url === '' ? '' : '<p><button class="btn btn-sm btn-success">Go Set Up</button> <button class="btn btn-sm">Not Now</button></p>';

        $html = <<<UMI
        <div class="alert alert-danger">
            <button type="button" class="close" data-dismiss="alert">
                <i class="ace-icon fa fa-times"></i>
            </button>
            <strong>
                <i class="ace-icon fa fa-times"></i>
                Oh whoops!
            </strong>
                $message
            <br />
            $showingButton
        </div>
UMI;
        return $html;
    }
    #endregion
}