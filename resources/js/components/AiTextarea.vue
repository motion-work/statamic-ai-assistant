<template>
  <AiInputWrapper :value="value" :inputType="'textarea'" @update:value="update"  />
</template>
<script>
import { FieldtypeMixin as StatamicFieldtypeMixin } from '@statamic/cms';
import AiInputWrapper from './AiInputWrapper.vue';

export default {
  mixins: [StatamicFieldtypeMixin],
  components: {
    AiInputWrapper,
  },
};
</script>

