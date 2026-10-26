<script setup lang="ts">
import { onMounted, shallowRef } from 'vue';
import RollingStockPanel from '@/components/RollingStockPanel.vue';
const stockRequest = shallowRef<any>();
import BusinessField from '@/components/BusinessField.vue';
import BusinessButton from '@/components/BusinessButton.vue';
import BusinessNotice from '@/components/BusinessNotice.vue';
import OperatorsPage from '@/pages/management/Operators.vue';
import AgenciesPage from '@/pages/management/Agencies.vue';
import AgencyGrantsPage from '@/pages/management/AgencyGrants.vue';
import WhitelistPage from '@/pages/management/Whitelist.vue';
import AssignmentsPage from '@/pages/management/Assignments.vue';
import StationDictionaryPage from '@/pages/management/StationDictionary.vue';
import FareRatesPage from '@/pages/management/FareRates.vue';
import DataCleanupPage from '@/pages/management/DataCleanup.vue';
import { initializeWorkbench } from '@/business/workbench';
onMounted(() => {
  stockRequest.value = initializeWorkbench().api;
});
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
        <div class="actions"><BusinessButton id="reserveSeats" /><BusinessButton id="cancelHold" /></div>
        <BusinessNotice id="holdInfo" />
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
    <div id="rollingStock" class="panel" hidden><RollingStockPanel :request="stockRequest" /></div>
    <div id="operators" class="panel" hidden><OperatorsPage /></div>
    <div id="agencies" class="panel" hidden><AgenciesPage /></div>
    <div id="agencyGrants" class="panel" hidden><AgencyGrantsPage /></div>
    <div id="whitelist" class="panel" hidden><WhitelistPage /></div>
    <div id="assignments" class="panel" hidden><AssignmentsPage /></div>
    <div id="stationDictionary" class="panel" hidden><StationDictionaryPage /></div>
    <div id="fareRates" class="panel" hidden><FareRatesPage /></div>
    <div id="dataCleanup" class="panel" hidden><DataCleanupPage /></div>
  </div>
</template>
