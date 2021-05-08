Vue.component('newsletter-default', {
	template: `<div class="my-3">
	<b-row>
		<b-col>
			<h1>Newsletter</h1>
		</b-col>
		<b-col cols="9" class="text-right">
			<b-button variant="primary" class="btn-add" v-b-modal.modal-newsletter-import>Import</b-button>
			<b-button variant="primary" class="btn-add" :href="baseApiPath + '/newsletter/csv-export'">CSV export</b-button>
			<b-button variant="primary" class="btn-add" v-b-modal.newsletter-settings>Settings</b-button>
			<b-button variant="primary" class="btn-add" v-b-modal.modal-newsletter-create>Add e-mail</b-button>
		</b-col>
	</b-row>
	<b-card v-if="items === null" class="text-center my-5">
		<b-spinner></b-spinner>
	</b-card>
	<template v-else>
		<newsletter-settings></newsletter-settings>
		<cms-filter>
			<b-form inline class="w-100">
				<div class="w-100">
					<div class="d-flex flex-column flex-sm-row align-items-sm-center pr-lg-0">
						<b-form-input size="sm" v-model="search.email" @input="sync" class="mr-3" placeholder="Search users..."></b-form-input>
						<b-form-select size="sm" :options="sourceTypes" v-model="search.source" @change="sync"></b-form-select>
						<b-form-select size="sm" :options="authorizedByUser" v-model="search.authorizedByUser" @change="sync"></b-form-select>
					</div>
				</div>
			</b-form>
		</cms-filter>
		<b-card>
			<table class="table table-sm cms-table-no-border-top">
				<tr>
					<th>E-mail</th>
					<th>Source</th>
					<th>Authorized</th>
					<th>Active</th>
					<th>Authorized date</th>
					<th>Inserted date</th>
					<th>Actions</th>
				</tr>
				<tr v-for="(item, offset) in items">
					<td>{{ item.email }}</td>
					<td>
						{{ item.source }}
					</td>
					<td class="text-center">
						<div v-if="item.authorized == 'authorized'" class="badge badge-pill badge-success">{{ authorizedByUser[item.authorized] }}</div>
						<div v-else class="badge badge-pill badge-danger">{{ authorizedByUser[item.authorized] }}</div>
					</td>
					<td>{{ item.isActive }}</td>
					<td>{{ item.authorizedDate }}</td>
					<td>{{ item.insertedDate }}</td>
					<td class="text-right">
						<b-btn @click="authorize(offset, item)" v-if="item.authorized !== 'authorized'" variant="primary" size="sm" title="Authorize">
							<b-icon icon="check2"></b-icon>
						</b-btn>
						<b-btn @click="decline(offset, item)" v-else variant="warning" size="sm" title="Decline">
							<b-icon icon="x"></b-icon>
						</b-btn>
						<b-btn @click="resendItem(item)" v-if="item.authorized !== 'authorized'" :disabled="isSending.includes(item.id)" variant="info" size="sm" title="Resend Confirmation">
							<b-icon v-if="!isSending.includes(item.id)" icon="cursor"></b-icon>
							<b-spinner v-else small></b-spinner>
						</b-btn>
						<b-btn variant="danger" size="sm" title="Remove" @click="toDelete = {...item, $offset: offset}" v-b-modal.modal-remove>
							<b-icon icon="trash"></b-icon>
						</b-btn>
					</td>
				</tr>
			</table>
			<b-pagination
				v-model="paginator.page"
				:per-page="paginator.itemsPerPage"
				@change="sync()"
				:total-rows="paginator.itemCount" align="right" size="sm" class="mb-0"></b-pagination>
		</b-card>
	</template>
	<newsletter-modal @sync="sync"></newsletter-modal>
	<modal-newsletter-import @sync="sync"></modal-newsletter-import>
	<modal-remove :callback="deleteRecipient" ></modal-remove>
</div>`,
	data() {
		return {
			items: null,
			sourceTypes: [],
			authorizedByUser: [],
			isSending: [],
			search: {
				email: '',
				source: null,
				authorizedByUser: null
			},
			paginator: {
				itemsPerPage: 0,
				page: 1,
				itemCount: 0,
			}
		}
	},
	mounted() {
		this.sync();
		setInterval(this.sync, 15000);
	},
	methods: {
		sync() {
			this.$nextTick(() => {
				let query = {
					email: this.search.email === '' ? null : this.search.email,
					type: this.search.type === '' ? null : this.search.type,
					page: this.paginator.page
				};
				axiosApi.get('newsletter?' + httpBuildQuery(query)).then(req => {
					let data = req.data;
					this.items = data.list;
					this.sourceTypes = {null: 'All types', ...data.sourceTypes};
					this.authorizedByUser = {null: 'All authorized', ...data.authorizedByUser};
					this.paginator = data.paginator;
				})
			})
		},
		decline(offset, item) {
			axiosApi.get(`newsletter/cancel?id=${item.id}`)
				.then(req => {
					this.recipients[offset].authorized = "canceled";
				})
		},
		authorize(offset, item) {
			axiosApi.get(`newsletter/authorize?id=${item.id}`)
				.then(req => {
					this.sync();
				})
		},
		resendItem(item) {
			this.isSending.push(item.id);
			axiosApi.get(`newsletter/send-mail?id=${item.id}`)
				.finally(() => this.isSending.splice(this.isSending.indexOf(item.id), 1));
		},
		deleteRecipient() {
			return axiosApi.get(`newsletter/delete?id=${this.toDelete.id}`).then(req => {
				this.recipients.splice(this.toDelete.$offset, 1);
			});
		}
	}
});

Vue.component('newsletter-settings', {
	template: `<b-modal id="newsletter-settings" title="Settings" @shown="load">

		<b-alert show variant="info">Set delay for automatic removal of newsletter e-mails</b-alert>

		<template v-if="!isLoading">
			<b-form-group label="Authorized e-mails">
				<b-form-input v-model="form.autoRemoveAuthorized"></b-form-input>
			</b-form-group>

			<b-form-group label="Unauthorized e-mails">
				<b-form-input v-model="form.autoRemoveUnAuthorized"></b-form-input>
			</b-form-group>
		</template>
		<div v-else class="text-center">
			<b-spinner></b-spinner>
		</div>

		<template v-slot:modal-footer>
			<modal-close></modal-close>
			<s-btn :callback="save">Save</s-btn>
		</template>
	</b-modal>`,
	data() {
		return {
			isLoading: false,
			form: {
				autoRemoveAuthorized: '',
				autoRemoveUnAuthorized: '',
			}
		}
	},
	methods: {
		save() {
			return axiosApi.post('newsletter/save-settings', this.form)
				.then(() => this.$bvModal.hide('newsletter-settings'))
		},
		load() {
			this.isLoading = true;
			axiosApi.get('newsletter/settings')
				.then(req => this.form = req.data)
				.finally(() => this.isLoading = false)
		}
	}
})

Vue.component('modal-newsletter-import', {
	template: `
	<b-modal id="modal-newsletter-import" size="xl" title="Import subscribers">
		<b-form ref="form" v-if="analyzed === false" autocomplete="off">
			<p>
				This import wizard is created for simple contact import.
				After form submission we analyze all the contacts you have typed and after correction we import them
			</p>
			<b-form-group>
				<template v-slot:label>
					Please enter all contacts:
				</template>
				<b-form-textarea rows="10" v-model="haystack" autocomplete="off" trim></b-form-textarea>
			</b-form-group>
			<p>Be aware, this may take several seconds please wait.</p>
		</b-form>
		<div v-else>
			<b-btn @click="selectAll" size="sm" class="my-2" variant="info">Select all</b-btn>
			<table class="table table-hover table-cardstyle">
				<thead>
					<tr>
						<th width="60">Import</th>
						<th>E-mail</th>
						<th>Known</th>
					</tr>
				</thead>
				<tbody>
					<template v-if="Object.keys(analysisResult).length > 0">
						<tr v-for="(item, mail) of analysisResult">
							<td class="text-center">
								<b-form-checkbox v-if="item.known === false" v-model="item.import" size="md"></b-form-checkbox>
							</td>
							<td @click="item.import = !item.import">
								<span>{{ mail }}</span>
							</td>
							<td>
								<div v-if="item.known === true" class="badge badge-pill badge-success">Known</div>
								<div v-else class="badge badge-pill badge-danger">Unknown</div>
							</td>
						</tr>
					</template>
					<tr v-else class="text-center">
						<td colspan="3">
							Could not parse any data. Please <b-link @click="analyzed = false">Return to Import</b-link> and edit them.
						</td>
					</tr>
				</tbody>
			</table>
			<b-form-group class="pt-3" v-if="Object.keys(analysisResult).length > 0">
				<template v-slot:label>
					Source <span class="text-danger">*</span>
				</template>
				<b-form-input type="text" class="w-100" maxlength="32" v-model="source" required autocomplete="off" trim></b-form-input>
			</b-form-group>
		</div>
		<template v-slot:modal-footer>
			<template v-if="analyzed === false">
				<b-btn size="sm" variant="white" @click="$bvModal.hide('modal-newsletter-import')">Close</b-btn>
				<b-btn size="sm" variant="primary" :disabled="isAnalyzing || haystack.length === 0" @click="analyze()">
					<template v-if="isAnalyzing">
						<b-spinner small class="mx-2"></b-spinner>
					</template>
					<template v-else>
						Continue to  import
					</template>
				</b-btn>
			</template>
			<template v-else>
				<b-btn size="sm" variant="white" @click="$bvModal.hide('modal-newsletter-import')">Close</b-btn>
				<b-btn size="sm" variant="secondary" @click="analyzed = false">Back to Import</b-btn>
				<b-btn size="sm" variant="primary" :disabled="isAnalyzing || source.length === 0" @click="importSelected()">
					<template v-if="isAnalyzing">
						<b-spinner small class="mx-2"></b-spinner>
					</template>
					<template v-else>
						Import selected
					</template>
				</b-btn>
			</template>
		</template>
	</b-modal>`,
	data() {
		return {
			isAnalyzing: false,
			analyzed: false,
			source: '',
			haystack: '',
			analysisResult: {},
		}
	},
	mounted() {
		this.$root.$on('bv::modal::hide', (bvEvent, modalId) => {
			if (modalId === 'modal-newsletter-import') {
				this.haystack = '';
				this.analyzed = false;
			}
		})
	},
	methods: {
		selectAll() {
			Object.keys(this.analysisResult).forEach(mail => {
				this.analysisResult[mail].import = true;
			})
		},
		importSelected() {
			let keys = Object.keys(this.analysisResult);
			let toImport = [];
			keys.forEach(key => {
				if (this.analysisResult[key].import === true) toImport.push(key);
			});

			axiosApi.post('newsletter/import', {
				emails: toImport,
				source: this.source,
			}).then(req => {
				this.$emit('sync');
				this.$bvModal.hide('modal-newsletter-import');
				this.analysisResult = {};
				this.source = '';
				this.haystack = '';
				this.analyzed = false;
			}).finally(() => this.isAnalyzing = false)
		},
		analyze() {
			this.isAnalyzing = true;
			axiosApi.post('newsletter/analyse-emails', {
				haystack: this.haystack
			}).then(req => {
				let emails = req.data.emails;
				let keys = Object.keys(emails);
				let finalEmails = {};

				keys.forEach(key => {
					finalEmails[key] = {
						known: emails[key],
						import: false,
					}
				});

				this.analysisResult = finalEmails;
				this.analyzed = true;
			}).finally(() => this.isAnalyzing = false)
		}
	}
});

Vue.component('newsletter-modal', {
	template: `
	<b-modal id="modal-newsletter-create" title="Add new e-mail">
			<b-form ref="form" autocomplete="off">
				<b-form-group>
					<template v-slot:label>
						E-mail <span class="text-danger">*</span>
					</template>
					<b-form-input type="text" v-model="form.email" required autocomplete="off" trim></b-form-input>
					<div class="invalid-feedback">
						E-mail is required
					</div>
				</b-form-group>
				<b-form-group>
					<template v-slot:label>
						Source
					</template>
					<b-form-input v-model="form.source" autocomplete="off" trim></b-form-input>
				</b-form-group>
			</b-form>
			<template v-slot:modal-footer>
				<b-btn size="sm" variant="white" @click="$bvModal.hide('modal-newsletter-create')">Close</b-btn>
				<b-btn size="sm" variant="primary" :disabled="isCreating" @click="registerNew(false)">
					<template v-if="isCreating">
						<b-spinner small class="mx-2"></b-spinner>
					</template>
					<template v-else>
						Save
					</template>
				</b-btn>
				<b-btn size="sm" variant="primary" :disabled="isCreating" @click="registerNew(true)">
					<template v-if="isCreating">
						<b-spinner small class="mx-2"></b-spinner>
					</template>
					<template v-else>
						Save and continue
					</template>
				</b-btn>
			</template>
		</b-modal>
	`,
	data() {
		return {
			isCreating: false,
			invalidMail: '',
			form: {
				email: null,
				source: null,
			}
		}
	},
	methods: {
		registerNew(resetForm) {
			let form = this.$refs.form;
			if (form.checkValidity()) {
				this.isCreating = true;
				axiosApi.post('newsletter/add-email', {
					email: this.form.email,
					source: this.form.source,
				}).then(req => {
					if (resetForm === false) {
						this.$bvModal.hide('modal-newsletter-create');
					} else {
						form.classList.remove('was-validated');
					}
					this.form.email = null;
					this.form.source = null;
					this.$emit('sync');
				}).finally(() => {
					this.isCreating = false
				})
			} else {
				form.classList.add('was-validated');
			}
		}
	}
});
