<script setup lang="ts">
import { onMounted } from 'vue';
import BusinessField from '@/components/BusinessField.vue';
import BusinessButton from '@/components/BusinessButton.vue';
import BusinessForm from '@/components/BusinessForm.vue';
import BusinessNotice from '@/components/BusinessNotice.vue';
import BusinessGrants from '@/components/BusinessGrants.vue';
import { initializeWorkbench } from '@/business/workbench';
onMounted(() => initializeWorkbench());
</script>
<template>
  <div class="workbench">
    <BusinessNotice id="status" />
    <t-card :bordered="false" class="business-card"
      ><h2>连接与身份</h2>
      <div class="row">
        <BusinessField id="apiKey" /><BusinessField id="agencyContext" />
        <div class="actions"><BusinessButton id="connect" /><BusinessButton id="disconnect" /></div>
      </div>
      <div id="identity" class="muted">尚未连接。密钥仅保存在当前页面内存中，刷新后需重新输入。</div></t-card
    >

    <div id="sales" class="panel">
      <t-card :bordered="false" class="business-card"
        ><h2>车站区间查询</h2>
        <div class="row">
          <BusinessField id="origin" /><BusinessField id="destination" /><BusinessField id="journeyDate" />
          <div class="actions"><BusinessButton id="search" /><BusinessButton id="swap" /></div>
        </div>
        <p class="muted">所有时刻为 UTC，北京时间加 8 小时。已导入演示日 2026-10-04，容量与运价为测试配置。</p>
        <div class="row">
          <BusinessField id="journeyTrainNumber" /><BusinessField id="journeyTimeFrom" /><BusinessField
            id="journeyTimeTo"
          />
        </div>
        <div id="journeyResults" class="table-wrap">
          <p class="muted">选择两站后查询，或从下方班次浏览选取有数据的区间。</p>
        </div></t-card
      >
      <t-card :bordered="false" class="business-card"
        ><h2>班次浏览</h2>
        <div class="row">
          <BusinessField id="tripDate" /><BusinessField id="tripOperator" /><BusinessField
            id="tripDepot"
          /><BusinessField id="tripIdMode" />
          <div class="actions">
            <BusinessButton id="browse" /><BusinessButton id="prev" /><BusinessButton id="next" />
          </div>
        </div>
        <p class="muted">
          车厂列表显示地图中的全部车厂，日期和运营方仅筛选班次。无班次的车厂可查看解析原因。趟次从 1
          开始，按该运营日同一车厂、股道的图定发车顺序编号。
        </p>
        <div class="row">
          <BusinessField id="tripTrainNumber" /><BusinessField id="tripTimeFrom" /><BusinessField id="tripTimeTo" />
        </div>
        <div id="depotInfo" class="hint"></div>
        <p id="pageInfo" class="muted"></p>
        <div id="tripResults" class="table-wrap"></div>
        <div id="tripDetail" hidden></div
      ></t-card>
      <t-card :bordered="false" class="business-card" id="issueSection"
        ><h2>出票</h2>
        <BusinessNotice id="selectedJourney" />
        <div class="row">
          <BusinessField id="issueDocumentId" /><BusinessField id="passenger" /><BusinessField id="requestKey" />
          <div class="actions"><BusinessButton id="newKey" /><BusinessButton id="issue" /></div>
        </div>
        <p id="issueDocumentInfo" class="muted">
          填写证件库 ID 时，姓名取自证件库并保存出票快照；留空可沿用旅客名称出票。
        </p>
        <p class="muted">网络失败时保留请求键重试可避免重复出票；再次购票请使用新请求键。当前没有真实支付。</p>
        <div id="issuedTicket"></div
      ></t-card>
    </div>
    <div id="tickets" class="panel" hidden>
      <t-card :bordered="false" class="business-card"
        ><h2>查票与退票</h2>
        <div class="row">
          <BusinessField id="ticketNumber" />
          <div class="actions"><BusinessButton id="lookup" /><BusinessButton id="refund" /></div>
        </div>
        <p class="muted">只能查询当前出票方的客票。退票释放库存，发车后不可退；再次退票返回同一状态。</p>
        <div id="ticketResult"></div
      ></t-card>
    </div>
    <div id="documents" class="panel" hidden>
      <t-card :bordered="false" class="business-card"
        ><h2>全局旅客证件库</h2>
        <div class="row">
          <BusinessField id="docSearchType" /><BusinessField id="docSearchNumber" /><BusinessField
            id="docSearchCountry"
          /><BusinessField id="docSearchName" />
          <div class="actions">
            <BusinessButton id="docSearch" /><BusinessButton id="docPrev" /><BusinessButton id="docNext" />
          </div>
        </div>
        <p id="docPageInfo" class="muted"></p>
        <div id="documentList" class="table-wrap"></div
      ></t-card>
      <t-card :bordered="false" class="business-card"
        ><h2 id="documentFormTitle">新增旅客证件</h2>
        <BusinessForm id="documentForm"
          ><div class="row">
            <BusinessField id="documentType" /><BusinessField id="documentNumber" /><BusinessField
              id="documentCountry"
            /><BusinessField id="documentBirthDate" />
          </div>
          <div class="row">
            <BusinessField id="documentSurname" /><BusinessField id="documentGivenName" /><BusinessField
              id="documentReason"
            />
          </div>
          <div class="row" style="margin-top: 14px">
            <BusinessButton id="documentFormSubmit" /><BusinessButton id="newDocument" /></div
        ></BusinessForm>
        <p id="documentVersion" class="muted">添加及更新时间由服务器记录。</p>
        <p class="muted">
          证件库跨运营方、出票方共用。相同类型、归属地、号码只登记一次；修改保留前后值、操作方及原因。已出票客票保留出票时的证件快照。
        </p></t-card
      >
      <t-card :bordered="false" class="business-card"
        ><h2>证件修改日志</h2>
        <div class="row"><BusinessButton id="documentHistoryPrev" /><BusinessButton id="documentHistoryNext" /></div>
        <pre id="documentHistory">从列表选择“修改日志”。</pre>
      </t-card>
    </div>
    <div id="operatorTickets" class="panel" hidden>
      <t-card :bordered="false" class="business-card"
        ><h2>运营方客票及 TLV 附加信息</h2>
        <div class="row">
          <BusinessField id="operatorScope" /><BusinessField id="operatorTicketDate" />
          <div class="actions">
            <BusinessButton id="operatorBrowse" /><BusinessButton id="operatorPrev" /><BusinessButton
              id="operatorNext"
            />
          </div>
        </div>
        <p id="operatorPageInfo" class="muted"></p>
        <div id="operatorTicketList" class="table-wrap"></div>
        <div class="row">
          <BusinessField id="operatorTicketNumber" />
          <div class="actions"><BusinessButton id="operatorLookup" /></div>
        </div>
        <pre id="operatorTicketInfo"></pre>
      </t-card>
      <t-card :bordered="false" class="business-card"
        ><h2>追加 TLV</h2>
        <BusinessForm id="tlvForm"
          ><div class="row">
            <BusinessField id="tlvTag" /><BusinessField id="tlvLabel" /><BusinessField id="tlvEncoding" />
          </div>
          <BusinessField id="tlvValue" />
          <p id="tlvLength" class="muted">长度：0 字节</p>
          <BusinessButton id="tlvFormSubmit"
        /></BusinessForm>
        <p class="muted">
          项目 TLV v1：2 字节标签 + 4 字节长度 + 值，均使用大端序；长度按值的实际字节数计算，单项上限 65536
          字节。可重复标签，追加不覆盖旧项；撤销保留日志，不设置到期时间。
        </p></t-card
      >
      <t-card :bordered="false" class="business-card"
        ><h2>附加信息及日志</h2>
        <p id="tlvPageInfo" class="muted"></p>
        <div class="row">
          <BusinessButton id="tlvPrev" /><BusinessButton id="tlvNext" /><BusinessButton id="tlvExport" />
        </div>
        <div id="tlvList" class="table-wrap"></div>
        <pre id="tlvHistory"></pre>
        <BusinessField id="tlvWire"
      /></t-card>
    </div>
    <div id="management" class="panel" hidden>
      <t-card :bordered="false" class="business-card"
        ><h2>轨道公里单价</h2>
        <p class="muted">
          按轨道标称限速分档计费，单价单位为分/公里。速度越高单价必须越高；各档里程合计后四舍五入到分。修改仅影响新报价及新出票，已出票金额保留快照。
        </p>
        <div class="row">
          <BusinessField
            v-for="speed in [2, 20, 30, 40, 60, 80, 120, 160, 200, 300, 900]"
            :key="speed"
            :id="'fareRate' + speed"
          />
        </div>
        <div class="row"><BusinessButton id="saveFareRates" /><BusinessButton id="reloadFareRates" /></div>
        <p id="farePolicyInfo" class="muted"></p
      ></t-card>
      <div class="grid">
        <t-card :bordered="false" class="business-card"
          ><h2>运营方管理</h2>
          <BusinessForm id="operatorForm"
            ><BusinessField id="opCode" /><BusinessField id="opName" /><BusinessField id="opContact" /><BusinessField
              id="opWhitelist" /><BusinessField id="opActive" />
            <div class="row" style="margin-top: 14px">
              <BusinessButton id="operatorFormSubmit" /><BusinessButton id="resetOperator" /></div
          ></BusinessForm>
          <div id="operatorsList" class="table-wrap"></div
        ></t-card>
        <t-card :bordered="false" class="business-card"
          ><h2>出票方管理</h2>
          <BusinessForm id="agencyForm"
            ><BusinessField id="agCode" /><BusinessField id="agName" /><BusinessField id="agIssuer" /><BusinessField
              id="agContact" /><BusinessField id="agActive" />
            <div class="row" style="margin-top: 14px">
              <BusinessButton id="agencyFormSubmit" /><BusinessButton id="resetAgency" /></div
          ></BusinessForm>
          <div id="agenciesList" class="table-wrap"></div
        ></t-card>
      </div>
      <t-card :bordered="false" class="business-card" style="margin-top: 18px"
        ><h2>出票方白名单关联与密钥</h2>
        <BusinessField id="grantAgency" /><BusinessGrants id="grantOperators" />
        <div class="row"><BusinessButton id="saveGrants" /><BusinessButton id="rotateKey" /></div>
        <p class="muted">
          停用机构立即阻止其新请求；移出已启用白名单只阻止新出票，不影响原有客票的查票和退票。运营方关闭白名单时所有启用出票方均可出票。重置密钥后旧密钥立即失效。
        </p>
        <BusinessNotice id="keyResult"
      /></t-card>
      <t-card :bordered="false" class="business-card"
        ><h2>车厂归属运营方</h2>
        <div class="row">
          <BusinessField id="assignDepot" /><BusinessField id="depotOperator" />
          <div class="actions"><BusinessButton id="saveDepotOperator" /></div>
        </div>
        <p class="muted">
          该车厂全部线路、全部运营日班次以及后续导入归属于所选运营方。已经出票的班次不能改为另一运营方。
        </p>
        <BusinessNotice id="depotOwnerStatus"
      /></t-card>
      <t-card :bordered="false" class="business-card"
        ><h2>运营方出票白名单</h2>
        <div class="row">
          <BusinessField id="whitelistOperator" /><BusinessField id="whitelistMode" />
          <div class="actions"><BusinessButton id="saveWhitelistMode" /><BusinessButton id="rotateOperatorKey" /></div>
        </div>
        <p class="muted">
          开启后空列表表示不允许任何出票方出新票；关闭后保留列表以便再次开启。既有客票仍可查询和退票。
        </p>
        <div class="row">
          <BusinessField id="whitelistAgency" />
          <div class="actions"><BusinessButton id="addWhitelistAgency" /></div>
        </div>
        <div id="whitelistList" class="table-wrap"></div>
        <BusinessNotice id="operatorKeyResult"
      /></t-card>
      <t-card :bordered="false" class="business-card"
        ><h2>按运营日清空数据</h2>
        <div class="row">
          <BusinessField id="clearDate" /><BusinessField id="clearScope" />
          <div class="actions"><BusinessButton id="clearPreview" /></div>
        </div>
        <p class="muted">
          按班次运营日清理全部维度和历史导入版本，包含跨午夜停站。客票范围包含已退票记录、乘车联、幂等请求和审计记录；全部数据同时清除运行图。车站、地图目录、机构、密钥及全局证件库和证件日志保留；客票
          TLV 同客票一起清理。仅清运行图时若有关联客票则拒绝。
        </p>
        <pre id="clearSummary"></pre>
        <div class="row">
          <BusinessField id="clearConfirm" />
          <div class="actions"><BusinessButton id="clearExecute" /></div></div
      ></t-card>
      <t-card :bordered="false" class="business-card"
        ><h2>设置班次运营方</h2>
        <div class="row">
          <BusinessField id="assignTripId" /><BusinessField id="assignOperator" />
          <div class="actions"><BusinessButton id="assign" /></div>
        </div>
        <p class="muted">只允许修改尚未出票的班次，已有客票（含已退票）保留原运营方。</p></t-card
      >
    </div>
  </div>
</template>
